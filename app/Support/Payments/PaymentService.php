<?php

namespace App\Support\Payments;

use App\Enums\BillingPeriod;
use App\Models\Business;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\Subscription;
use App\Notifications\PaymentFailed;
use App\Support\SubscriptionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Connects Paystack to the existing subscription workflow. Integrates
 * with SubscriptionService (requestPlanChange() for the pending request;
 * activateFromPayment() / scheduleDowngrade() for activation) rather than
 * writing to Business/Subscription directly — this is the one place a
 * payment ever results in a subscription change.
 *
 * Billing period: the owner may choose monthly or yearly when paying (see
 * BillingPeriod). There is still only one price per plan in the database
 * (plans.price, labelled "/ month" throughout the UI) — yearly is a fixed,
 * documented 12x multiplier of that same database price, computed here,
 * never a separate price a browser can submit or a value invented ad hoc.
 *
 * Upgrade vs. downgrade vs. renewal, decided once payment is verified:
 *   - Same plan as current, still in good standing → renewal; the new
 *     period starts when the current one ends (see
 *     SubscriptionService::activateFromPayment()).
 *   - A higher-priced plan → upgrade; activates immediately, full price,
 *     no proration (the previous period's remaining days are forfeited).
 *   - A lower-priced plan while the current one is still active →
 *     downgrade; paid for now, but only takes effect once the current
 *     period ends (see SubscriptionService::scheduleDowngrade() and the
 *     subscriptions:expire command, which promotes it).
 */
class PaymentService
{
    public function __construct(
        private readonly PaystackClient $paystack,
        private readonly SubscriptionService $subscriptions,
    ) {}

    /**
     * Start a payment for one business, for one plan. The amount is
     * always the plan's current database price (times the billing
     * period's fixed multiplier) — nothing the browser submits is ever
     * trusted for it. This never activates anything; it only creates a
     * pending subscription request (or reuses an existing, not-yet-paid
     * one, via SubscriptionService::requestPlanChange()) and a pending
     * payment transaction, then asks Paystack for a checkout URL.
     */
    public function initiate(Business $business, Plan $plan, BillingPeriod $billingPeriod, string $email, string $callbackUrl): PaymentInitiationResult
    {
        if (! $plan->is_active) {
            return PaymentInitiationResult::failure('This plan is not available.');
        }

        $amountInKobo = (int) round(((float) $plan->price) * 100) * $billingPeriod->priceMultiplier();

        if ($amountInKobo <= 0) {
            return PaymentInitiationResult::failure('This plan does not require payment.');
        }

        $subscriptionRequest = $this->subscriptions->requestPlanChange($business, $plan, $billingPeriod);

        $transaction = $business->paymentTransactions()->create([
            'plan_id' => $plan->id,
            'subscription_id' => $subscriptionRequest->id,
            'reference' => PaymentTransaction::generateReference(),
            'amount' => $amountInKobo,
            'currency' => 'NGN',
            'status' => PaymentTransaction::STATUS_PENDING,
        ]);

        $result = $this->paystack->initializeTransaction($transaction->reference, $amountInKobo, $email, $callbackUrl);

        if (! $result->successful) {
            $transaction->update([
                'status' => PaymentTransaction::STATUS_FAILED,
                'failure_reason' => 'initialization_failed',
            ]);

            return PaymentInitiationResult::failure($result->error ?? 'Could not start payment.');
        }

        $transaction->update(['initialized_at' => now()]);

        return PaymentInitiationResult::success($result->authorizationUrl, $transaction->fresh());
    }

    /**
     * Independently verify one transaction with Paystack and, only if
     * every check passes, activate (or schedule) its subscription. Safe
     * to call more than once (from the callback and/or the webhook, in
     * either order, even concurrently) — a row lock plus an
     * already-processed guard ensure a transaction is only ever resolved
     * once.
     *
     * $expectedBusiness, when given, must match the transaction's
     * business — this is what stops a transaction belonging to one
     * business from ever being resolved (or its details read) via another
     * business's authenticated session.
     */
    public function verifyAndActivate(PaymentTransaction $transaction, ?Business $expectedBusiness = null): PaymentVerificationOutcome
    {
        if ($expectedBusiness && $transaction->business_id !== $expectedBusiness->id) {
            return PaymentVerificationOutcome::rejected($transaction, PaymentVerificationOutcome::NOT_OWNER);
        }

        $outcome = DB::transaction(function () use ($transaction) {
            /** @var PaymentTransaction $locked */
            $locked = PaymentTransaction::whereKey($transaction->id)->lockForUpdate()->firstOrFail();

            if (in_array($locked->status, [PaymentTransaction::STATUS_SUCCESSFUL, PaymentTransaction::STATUS_FAILED, PaymentTransaction::STATUS_ABANDONED], true)) {
                return PaymentVerificationOutcome::alreadyProcessed($locked);
            }

            $result = $this->paystack->verifyTransaction($locked->reference);

            if (! $result->successful) {
                // Paystack couldn't be reached/verified — left pending so
                // a later retry (callback or webhook) can still resolve it.
                Log::warning('payment.verify.unavailable', ['business_id' => $locked->business_id, 'reference' => $locked->reference]);

                return PaymentVerificationOutcome::rejected($locked, PaymentVerificationOutcome::VERIFICATION_UNAVAILABLE);
            }

            if ($result->reference !== $locked->reference) {
                $locked->update(['status' => PaymentTransaction::STATUS_FAILED, 'verified_at' => now(), 'failure_reason' => 'reference_mismatch']);

                return PaymentVerificationOutcome::rejected($locked, PaymentVerificationOutcome::REFERENCE_MISMATCH);
            }

            if ($result->amount !== $locked->amount) {
                $locked->update(['status' => PaymentTransaction::STATUS_FAILED, 'verified_at' => now(), 'failure_reason' => 'amount_mismatch']);
                Log::warning('payment.verify.amount_mismatch', ['business_id' => $locked->business_id, 'reference' => $locked->reference]);

                return PaymentVerificationOutcome::rejected($locked, PaymentVerificationOutcome::AMOUNT_MISMATCH);
            }

            if (strtoupper((string) $result->currency) !== strtoupper($locked->currency)) {
                $locked->update(['status' => PaymentTransaction::STATUS_FAILED, 'verified_at' => now(), 'failure_reason' => 'currency_mismatch']);

                return PaymentVerificationOutcome::rejected($locked, PaymentVerificationOutcome::CURRENCY_MISMATCH);
            }

            if ($result->paystackStatus !== 'success') {
                $locked->update([
                    'status' => $result->paystackStatus === 'abandoned' ? PaymentTransaction::STATUS_ABANDONED : PaymentTransaction::STATUS_FAILED,
                    'verified_at' => now(),
                    'failure_reason' => $result->paystackStatus,
                    'paystack_transaction_id' => $result->transactionId,
                    'channel' => $result->channel,
                ]);

                return PaymentVerificationOutcome::unsuccessfulPayment($locked->fresh());
            }

            $locked->update([
                'status' => PaymentTransaction::STATUS_SUCCESSFUL,
                'verified_at' => now(),
                'paystack_transaction_id' => $result->transactionId,
                'channel' => $result->channel,
            ]);

            $this->activateSubscription($locked);

            Log::info('payment.verify.success', ['business_id' => $locked->business_id, 'reference' => $locked->reference]);

            return PaymentVerificationOutcome::success($locked->fresh());
        });

        if (! $outcome->successful && in_array($outcome->status, [PaymentTransaction::STATUS_FAILED, PaymentTransaction::STATUS_ABANDONED], true)) {
            $this->notifyPaymentFailed($outcome->transaction);
        }

        return $outcome;
    }

    /**
     * Decides, now that payment is verified, whether this is a renewal,
     * an upgrade, or a downgrade, and activates (or schedules)
     * accordingly. A second verification of the same (already
     * successful) transaction never reaches here.
     */
    private function activateSubscription(PaymentTransaction $transaction): void
    {
        if (! $transaction->subscription_id) {
            return;
        }

        $subscription = Subscription::find($transaction->subscription_id);

        if (! $subscription || $subscription->status !== Subscription::STATUS_PENDING) {
            return;
        }

        $business = $transaction->business;
        $current = $business?->currentSubscription;
        $billingPeriod = BillingPeriod::fromValue($subscription->billing_period);

        $isDowngrade = $current
            && $current->isInGoodStanding()
            && $current->plan_id !== $subscription->plan_id
            && $current->plan
            && $subscription->plan
            && (float) $subscription->plan->price < (float) $current->plan->price;

        if ($isDowngrade) {
            $this->subscriptions->scheduleDowngrade($subscription, $current, $billingPeriod);

            return;
        }

        $this->subscriptions->activateFromPayment($subscription, $billingPeriod);
    }

    /**
     * Never lets a notification failure interrupt verification — this is
     * called after the verification transaction has already committed.
     */
    private function notifyPaymentFailed(PaymentTransaction $transaction): void
    {
        try {
            $transaction->business?->owner?->notify(new PaymentFailed($transaction));
        } catch (Throwable $e) {
            Log::warning('payment.notify.failed_failed', ['transaction_id' => $transaction->id]);
        }
    }
}
