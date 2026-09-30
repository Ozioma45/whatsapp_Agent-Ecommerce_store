<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Support\Payments\PaymentService;
use App\Support\Payments\PaymentVerificationOutcome;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PaymentController extends Controller
{
    /**
     * Start a Paystack payment for a plan. The business is always
     * resolved from the authenticated user — never from anything the
     * request submits — so an owner can never pay for another business by
     * manipulating form fields.
     */
    public function initiate(Request $request, PaymentService $service): RedirectResponse
    {
        $business = $request->user()->business()->firstOrFail();

        $validated = $request->validate([
            'plan_id' => ['required', Rule::exists('plans', 'id')->where('is_active', true)],
        ]);

        $plan = Plan::findOrFail($validated['plan_id']);

        if ($business->plan_id === $plan->id) {
            return redirect()->route('subscription.edit')->with('error', 'You are already on this plan.');
        }

        $result = $service->initiate(
            $business,
            $plan,
            $request->user()->email,
            route('subscription.payment.callback'),
        );

        if (! $result->successful) {
            return redirect()->route('subscription.edit')
                ->with('error', $result->error ?? 'Could not start payment. Please try again.');
        }

        return redirect()->away($result->authorizationUrl);
    }

    /**
     * Paystack redirects the customer back here after checkout. The
     * transaction is looked up strictly by its own unique reference and
     * scoped to the authenticated user's own business, then independently
     * re-verified with Paystack — the redirect itself (including any
     * "status" query parameter Paystack appends) is never trusted.
     */
    public function callback(Request $request, PaymentService $service): RedirectResponse
    {
        $business = $request->user()->business()->firstOrFail();
        $reference = (string) $request->query('reference', '');

        $transaction = $business->paymentTransactions()->where('reference', $reference)->first();

        if (! $transaction) {
            return redirect()->route('subscription.edit')->with('error', 'We could not find that payment.');
        }

        $outcome = $service->verifyAndActivate($transaction, $business);

        return redirect()->route('subscription.edit')->with($this->flashFor($outcome));
    }

    /**
     * @return array<string, string>
     */
    private function flashFor(PaymentVerificationOutcome $outcome): array
    {
        if ($outcome->status === PaymentTransaction::STATUS_SUCCESSFUL) {
            return ['status' => 'Payment received — your plan has been updated.'];
        }

        if ($outcome->status === PaymentVerificationOutcome::ALREADY_PROCESSED) {
            return ['status' => 'This payment was already confirmed.'];
        }

        if ($outcome->status === PaymentTransaction::STATUS_ABANDONED) {
            return ['error' => 'That payment was not completed.'];
        }

        if ($outcome->status === PaymentVerificationOutcome::VERIFICATION_UNAVAILABLE) {
            return ['error' => 'We could not confirm this payment with Paystack yet. Please check back shortly.'];
        }

        return ['error' => 'That payment could not be verified.'];
    }
}
