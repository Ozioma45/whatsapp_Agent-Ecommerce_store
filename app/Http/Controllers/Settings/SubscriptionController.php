<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Subscription;
use App\Support\SubscriptionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SubscriptionController extends Controller
{
    /**
     * Show the authenticated user's own business subscription: current
     * plan, status, dates, the plan's features/limits, every available
     * plan with its price, and any pending request.
     */
    public function edit(Request $request): View
    {
        $business = $request->user()->business()->with(['plan.features', 'currentSubscription'])->firstOrFail();

        $pending = $business->subscriptions()->where('status', Subscription::STATUS_PENDING)
            ->with('plan')->latest()->first();

        return view('settings.subscription', [
            'business' => $business,
            'subscription' => $business->currentSubscription,
            // A plain, not-yet-paid admin-review request vs. an already
            // paid-for, scheduled downgrade are shown differently.
            'pendingRequest' => $pending && ! $pending->isScheduledChange() ? $pending : null,
            'scheduledChange' => $pending && $pending->isScheduledChange() ? $pending : null,
            'plans' => Plan::where('is_active', true)->orderBy('price')->get(),
            'payments' => $business->paymentTransactions()->with('plan')->latest()->limit(10)->get(),
        ]);
    }

    /**
     * Submit a plan-change request. This never changes the business's
     * actual plan — see SubscriptionService::requestPlanChange().
     */
    public function requestChange(Request $request, SubscriptionService $service): RedirectResponse
    {
        $business = $request->user()->business()->firstOrFail();

        $validated = $request->validate([
            'plan_id' => ['required', Rule::exists('plans', 'id')->where('is_active', true)],
        ]);

        $plan = Plan::findOrFail($validated['plan_id']);

        if ($business->plan_id === $plan->id) {
            return redirect()->route('subscription.edit')->with('error', 'You are already on this plan.');
        }

        $service->requestPlanChange($business, $plan);

        return redirect()->route('subscription.edit')
            ->with('status', "Your request to move to the {$plan->name} plan has been submitted and is pending review.");
    }

    /**
     * Ask that the current subscription not continue past its expiry.
     * Access is untouched — see SubscriptionService::cancelRenewal(). The
     * subscription acted on is always the authenticated business's own
     * current one; no id is ever accepted from the request.
     */
    public function cancel(Request $request, SubscriptionService $service): RedirectResponse
    {
        $business = $request->user()->business()->firstOrFail();
        $subscription = $business->currentSubscription;

        if (! $subscription || ! $subscription->isInGoodStanding()) {
            return redirect()->route('subscription.edit')->with('error', 'There is no active subscription to cancel.');
        }

        $service->cancelRenewal($subscription);

        return redirect()->route('subscription.edit')
            ->with('status', 'Your subscription will not renew after '.($subscription->expires_at?->format('d M Y') ?? 'its current period ends').'. You can still use your plan until then.');
    }

    /**
     * Reverse a cancellation requested before expiry.
     */
    public function resume(Request $request, SubscriptionService $service): RedirectResponse
    {
        $business = $request->user()->business()->firstOrFail();
        $subscription = $business->currentSubscription;

        if (! $subscription || ! $subscription->hasRequestedCancellation()) {
            return redirect()->route('subscription.edit')->with('error', 'There is no pending cancellation to resume.');
        }

        $service->resumeRenewal($subscription);

        return redirect()->route('subscription.edit')->with('status', 'Your subscription will continue as normal.');
    }
}
