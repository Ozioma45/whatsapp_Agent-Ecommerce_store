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

        return view('settings.subscription', [
            'business' => $business,
            'subscription' => $business->currentSubscription,
            'pendingRequest' => $business->subscriptions()->where('status', Subscription::STATUS_PENDING)->latest()->first(),
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
}
