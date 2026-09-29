<?php

namespace App\Support;

use App\Models\Business;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;

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
     * business's actual plan — it only records a pending request for an
     * admin to review. A second request while one is already pending
     * updates that same row instead of creating a duplicate.
     */
    public function requestPlanChange(Business $business, Plan $plan): Subscription
    {
        $pending = $business->subscriptions()->where('status', Subscription::STATUS_PENDING)->first();

        if ($pending) {
            $pending->update(['plan_id' => $plan->id, 'requested_at' => now()]);

            return $pending->fresh();
        }

        return $business->subscriptions()->create([
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_PENDING,
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

            if ($business->currentSubscription && $business->currentSubscription->id !== $request->id) {
                $business->currentSubscription->update(['status' => Subscription::STATUS_CANCELLED]);
            }

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
            if ($business->currentSubscription) {
                $business->currentSubscription->update(['status' => Subscription::STATUS_CANCELLED]);
            }

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
