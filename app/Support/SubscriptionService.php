<?php

namespace App\Support;

use App\Enums\BillingPeriod;
use App\Models\Business;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\SubscriptionActivated;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The one place a business's subscription state is ever changed. Every
 * method preserves history (an old "current" row is marked cancelled, not
 * deleted or overwritten) and keeps Business::plan_id in sync with
 * whichever subscription becomes current, inside one transaction — so
 * PlanFeatureService (which still reads plan_id exactly as before) is
 * never out of step with the subscription that produced it.
 */
class SubscriptionService
{
    /**
     * A business owner requests a plan change. This never changes the
     * business's actual plan — it only records a pending request (for an
     * admin to review, or for App\Support\Payments\PaymentService to
     * activate once payment is verified). A second request while one is
     * already pending updates that same row instead of creating a
     * duplicate — this also means starting a new payment for a plan
     * reuses (and doesn't duplicate) an existing unpaid request.
     */
    public function requestPlanChange(Business $business, Plan $plan, ?BillingPeriod $billingPeriod = null): Subscription
    {
        $pending = $business->subscriptions()->where('status', Subscription::STATUS_PENDING)
            ->whereDoesntHave('paymentTransactions', fn ($q) => $q->where('status', PaymentTransaction::STATUS_SUCCESSFUL))
            ->first();

        if ($pending) {
            $pending->update([
                'plan_id' => $plan->id,
                'billing_period' => $billingPeriod?->value,
                'requested_at' => now(),
            ]);

            return $pending->fresh();
        }

        return $business->subscriptions()->create([
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_PENDING,
            'billing_period' => $billingPeriod?->value,
            'requested_at' => now(),
        ]);
    }

    /**
     * An admin approves a pending request: it becomes the business's new
     * current, active subscription, and whatever was current before is
     * superseded (marked cancelled, kept for history).
     */
    public function approve(Subscription $request, User $admin, ?string $startsAt = null, ?string $expiresAt = null, ?string $billingPeriod = null): Subscription
    {
        return DB::transaction(function () use ($request, $admin, $startsAt, $expiresAt, $billingPeriod) {
            $business = $request->business;

            $this->supersedeIfStillActive($business->currentSubscription, $request->id);

            $request->update([
                'status' => Subscription::STATUS_ACTIVE,
                'starts_at' => $startsAt ?: now()->toDateString(),
                'expires_at' => $expiresAt,
                'billing_period' => $billingPeriod,
                'decided_at' => now(),
                'decided_by' => $admin->id,
            ]);

            $business->update([
                'plan_id' => $request->plan_id,
                'current_subscription_id' => $request->id,
            ]);

            return $request->fresh();
        });
    }

    /**
     * Activates a subscription after independently verified Paystack
     * payment (see App\Support\Payments\PaymentService) — the automated
     * counterpart to approve(), which requires a human admin decision.
     * Otherwise identical: whatever was previously current is superseded
     * (marked cancelled, kept for history), and Business::plan_id /
     * current_subscription_id are kept in sync in the same transaction.
     *
     * decided_by is deliberately left null: no admin made this decision.
     *
     * Renewal rule: if the business's current subscription is for the
     * *same plan* and still in good standing, the new period starts the
     * moment the old one ends (so paying early never shortens what was
     * already paid for). Otherwise — no current subscription, it already
     * expired, or this is a different plan (an upgrade) — the new period
     * starts now, from the moment of verified activation. Upgrades are
     * never prorated/credited: the full period is paid for fresh.
     */
    public function activateFromPayment(Subscription $request, BillingPeriod $billingPeriod): Subscription
    {
        return DB::transaction(function () use ($request, $billingPeriod) {
            $business = $request->business;
            $previous = $business->currentSubscription;

            $startsAt = $this->renewalStartDate($previous, $request);
            $expiresAt = $billingPeriod->addTo($startsAt);

            $this->supersedeIfStillActive($previous, $request->id);

            $request->update([
                'status' => Subscription::STATUS_ACTIVE,
                'starts_at' => $startsAt->toDateString(),
                'expires_at' => $expiresAt->toDateString(),
                'billing_period' => $billingPeriod->value,
                'decided_at' => now(),
            ]);

            $business->update([
                'plan_id' => $request->plan_id,
                'current_subscription_id' => $request->id,
            ]);

            $this->notifyActivated($request->fresh());

            return $request->fresh();
        });
    }

    /**
     * A downgrade (a verified, paid-for move to a cheaper plan while the
     * current one is still active) does not activate immediately — it
     * stays "pending" but now carries its own future start date (the
     * current subscription's expiry) and end date, and is recognisable as
     * paid-and-scheduled via Subscription::isScheduledChange(). The
     * current plan and its entitlements are completely untouched until
     * subscriptions:expire promotes it (see promoteScheduledChanges()).
     */
    public function scheduleDowngrade(Subscription $request, Subscription $currentSubscription, BillingPeriod $billingPeriod): Subscription
    {
        $startsAt = $currentSubscription->expires_at ?? now();
        $expiresAt = $billingPeriod->addTo($startsAt);

        $request->update([
            'starts_at' => $startsAt->toDateString(),
            'expires_at' => $expiresAt->toDateString(),
            'billing_period' => $billingPeriod->value,
        ]);

        return $request->fresh();
    }

    /**
     * Promotes every scheduled downgrade whose start date has arrived —
     * called by the subscriptions:expire command. Safe to run repeatedly:
     * a promoted row becomes "active" and so never matches this query
     * again.
     */
    public function promoteScheduledChanges(): int
    {
        $promoted = 0;

        Subscription::where('status', Subscription::STATUS_PENDING)
            ->whereNotNull('starts_at')
            ->where('starts_at', '<=', now()->toDateString())
            ->whereHas('paymentTransactions', fn ($query) => $query->where('status', PaymentTransaction::STATUS_SUCCESSFUL))
            ->get()
            ->each(function (Subscription $scheduled) use (&$promoted) {
                $business = $scheduled->business;

                $this->supersedeIfStillActive($business->currentSubscription, $scheduled->id);

                $scheduled->update(['status' => Subscription::STATUS_ACTIVE, 'decided_at' => now()]);

                $business->update([
                    'plan_id' => $scheduled->plan_id,
                    'current_subscription_id' => $scheduled->id,
                ]);

                $this->notifyActivated($scheduled->fresh());

                $promoted++;
            });

        return $promoted;
    }

    /**
     * The business owner asks for their subscription not to continue past
     * its current expiry. Access is untouched — see Subscription::$cancelled_at.
     */
    public function cancelRenewal(Subscription $subscription): Subscription
    {
        $subscription->update(['cancelled_at' => now()]);

        return $subscription->fresh();
    }

    /**
     * The business owner changes their mind before expiry.
     */
    public function resumeRenewal(Subscription $subscription): Subscription
    {
        $subscription->update(['cancelled_at' => null]);

        return $subscription->fresh();
    }

    /**
     * Same plan, still in good standing → continue on from its own
     * expiry. Anything else (no subscription, already expired, or a
     * different plan entirely) → start now.
     */
    private function renewalStartDate(?Subscription $previous, Subscription $request): Carbon
    {
        $isRenewalOfSamePlan = $previous && $previous->plan_id === $request->plan_id;

        if ($isRenewalOfSamePlan && $previous->isInGoodStanding() && $previous->expires_at) {
            return $previous->expires_at->copy();
        }

        return now();
    }

    /**
     * Marks $previous cancelled only if it is still "active" — never
     * overwrites a status a *different* process already moved it to in
     * the same request (most notably: the subscriptions:expire command
     * marking it "expired" immediately before promoteScheduledChanges()
     * runs in the same pass). $newCurrentId is excluded as a safety net
     * for the (never actually reachable) case of superseding a row with
     * itself.
     */
    private function supersedeIfStillActive(?Subscription $previous, ?int $newCurrentId): void
    {
        if ($previous && $previous->id !== $newCurrentId && $previous->status === Subscription::STATUS_ACTIVE) {
            $previous->update(['status' => Subscription::STATUS_CANCELLED]);
        }
    }

    /**
     * Never lets a notification failure interrupt activation — this
     * always runs inside the same DB transaction as the activation
     * itself, and a mail/queue error here must not roll it back.
     */
    private function notifyActivated(Subscription $subscription): void
    {
        try {
            $owner = $subscription->business?->owner;
            $owner?->notify(new SubscriptionActivated($subscription));
        } catch (\Throwable $e) {
            Log::warning('subscription.notify.activated_failed', ['subscription_id' => $subscription->id]);
        }
    }

    /**
     * An admin rejects a pending request. The business's actual plan and
     * current subscription are untouched.
     */
    public function reject(Subscription $request, User $admin): Subscription
    {
        $request->update([
            'status' => Subscription::STATUS_CANCELLED,
            'decided_at' => now(),
            'decided_by' => $admin->id,
        ]);

        return $request->fresh();
    }

    /**
     * An admin assigns a plan directly, with no prior request — the same
     * manual override Admin\BusinessController has always supported, now
     * recorded as a proper subscription episode.
     */
    public function assignPlanDirectly(Business $business, Plan $plan, User $admin, ?string $startsAt = null, ?string $expiresAt = null, ?string $billingPeriod = null): Subscription
    {
        return DB::transaction(function () use ($business, $plan, $admin, $startsAt, $expiresAt, $billingPeriod) {
            $this->supersedeIfStillActive($business->currentSubscription, null);

            $subscription = $business->subscriptions()->create([
                'plan_id' => $plan->id,
                'status' => Subscription::STATUS_ACTIVE,
                'starts_at' => $startsAt ?: now()->toDateString(),
                'expires_at' => $expiresAt,
                'billing_period' => $billingPeriod,
                'decided_at' => now(),
                'decided_by' => $admin->id,
            ]);

            $business->update([
                'plan_id' => $plan->id,
                'current_subscription_id' => $subscription->id,
            ]);

            return $subscription;
        });
    }

    /**
     * Suspends a business's current subscription in place (same row —
     * this is a status change, not a new episode). Denies entitlements
     * until reactivated.
     */
    public function suspend(Subscription $subscription, User $admin): Subscription
    {
        $subscription->update([
            'status' => Subscription::STATUS_SUSPENDED,
            'decided_at' => now(),
            'decided_by' => $admin->id,
        ]);

        return $subscription->fresh();
    }

    /**
     * Reactivates a suspended (or expired) subscription in place.
     */
    public function reactivate(Subscription $subscription, User $admin): Subscription
    {
        $subscription->update([
            'status' => Subscription::STATUS_ACTIVE,
            'decided_at' => now(),
            'decided_by' => $admin->id,
        ]);

        return $subscription->fresh();
    }
}
