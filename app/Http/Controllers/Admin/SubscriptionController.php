<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BillingPeriod;
use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Support\SubscriptionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SubscriptionController extends Controller
{
    /**
     * The review queue: every business-owner plan-change request still
     * awaiting a decision, oldest first.
     */
    public function index(): View
    {
        return view('admin.subscriptions.index', [
            'pendingRequests' => Subscription::with(['business', 'plan'])
                ->where('status', Subscription::STATUS_PENDING)
                ->oldest('requested_at')
                ->get(),
        ]);
    }

    /**
     * Approve a pending request — it becomes the business's new active
     * subscription (see SubscriptionService::approve()). Only ever acts on
     * a request that is actually still pending, so a request can't be
     * approved twice or after being rejected.
     */
    public function approve(Request $request, string $subscription, SubscriptionService $service): RedirectResponse
    {
        $subscription = Subscription::where('status', Subscription::STATUS_PENDING)->findOrFail($subscription);

        $validated = $request->validate([
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after:starts_at'],
            'billing_period' => ['nullable', Rule::in(BillingPeriod::values())],
        ]);

        $service->approve(
            $subscription,
            $request->user(),
            $validated['starts_at'] ?? null,
            $validated['expires_at'] ?? null,
            $validated['billing_period'] ?? null,
        );

        return redirect()->route('admin.subscriptions.index')->with('status', 'Request approved.');
    }

    /**
     * Reject a pending request. The business's plan is left untouched.
     */
    public function reject(Request $request, string $subscription, SubscriptionService $service): RedirectResponse
    {
        $subscription = Subscription::where('status', Subscription::STATUS_PENDING)->findOrFail($subscription);

        $service->reject($subscription, $request->user());

        return redirect()->route('admin.subscriptions.index')->with('status', 'Request rejected.');
    }
}
