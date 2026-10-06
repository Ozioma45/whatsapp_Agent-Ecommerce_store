@extends('layouts.app')

@section('title', 'Subscription — ' . config('app.name'))

@section('content')
    <h1 class="mb-6 text-2xl font-semibold">Subscription</h1>

    @if (session('status'))
        <p class="mb-4 rounded-md bg-green-50 px-4 py-3 text-sm text-green-700">{{ session('status') }}</p>
    @endif
    @if (session('error'))
        <p class="mb-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{{ session('error') }}</p>
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <div class="rounded-lg border border-gray-200 bg-white p-6">
                <h2 class="mb-4 text-sm font-medium text-gray-500">Current subscription</h2>
                <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-gray-500">Plan</dt>
                        <dd class="text-gray-900">{{ $business->plan?->name ?? 'No plan assigned' }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Status</dt>
                        <dd class="text-gray-900">{{ $subscription?->statusLabel() ?? 'Active' }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Billing period</dt>
                        <dd class="text-gray-900">{{ $subscription?->billing_period ? ucfirst($subscription->billing_period) : '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Start date</dt>
                        <dd class="text-gray-900">{{ $subscription?->starts_at?->format('d M Y') ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Expiry date</dt>
                        <dd class="text-gray-900">{{ $subscription?->expires_at?->format('d M Y') ?? 'Does not expire' }}</dd>
                    </div>
                    @if ($subscription?->daysRemaining() !== null)
                        <div>
                            <dt class="text-gray-500">Days remaining</dt>
                            <dd class="text-gray-900">{{ $subscription->daysRemaining() }}</dd>
                        </div>
                    @endif
                </dl>

                @if ($subscription && $subscription->isInGoodStanding())
                    <div class="mt-4">
                        @if ($subscription->hasRequestedCancellation())
                            <p class="rounded-md bg-yellow-50 px-4 py-3 text-sm text-yellow-800">
                                Your subscription will not renew after {{ $subscription->expires_at?->format('d M Y') }}. You can keep using
                                your plan until then.
                            </p>
                            <form method="POST" action="{{ route('subscription.resume') }}" class="mt-2">
                                @csrf
                                <button type="submit" class="text-xs text-gray-700 underline hover:text-gray-900">Resume renewal</button>
                            </form>
                        @else
                            <form method="POST" action="{{ route('subscription.cancel') }}"
                                onsubmit="return confirm('Cancel renewal? You will keep your current plan until it expires, then it will not continue.');">
                                @csrf
                                <button type="submit" class="text-xs text-red-600 underline hover:text-red-700">Cancel renewal</button>
                            </form>
                        @endif
                    </div>
                @endif

                @if ($pendingRequest)
                    <p class="mt-4 rounded-md bg-yellow-50 px-4 py-3 text-sm text-yellow-800">
                        <strong>Pending plan change:</strong> a request to move to the
                        <strong>{{ $pendingRequest->plan?->name ?? 'selected' }}</strong> plan is awaiting admin review. Your current plan is unaffected until then.
                    </p>
                @endif

                @if ($scheduledChange)
                    <p class="mt-4 rounded-md bg-blue-50 px-4 py-3 text-sm text-blue-800">
                        <strong>Scheduled change:</strong> your plan will change to
                        <strong>{{ $scheduledChange->plan?->name ?? 'the selected plan' }}</strong> on {{ $scheduledChange->starts_at?->format('d M Y') }},
                        once your current paid period ends. You've already paid for that period — your current plan stays active until then.
                    </p>
                @endif
            </div>

            @if ($business->plan)
                <div class="rounded-lg border border-gray-200 bg-white p-6">
                    <h2 class="mb-4 text-sm font-medium text-gray-500">Features included in your plan</h2>
                    <ul class="space-y-2 text-sm">
                        @foreach ($business->plan->features as $feature)
                            <li class="flex items-center justify-between">
                                <span class="text-gray-900">{{ $feature->name }}</span>
                                <span class="text-gray-500">
                                    @if (! $feature->pivot->enabled)
                                        Not included
                                    @elseif ($feature->type === \App\Models\Feature::TYPE_LIMIT)
                                        {{ $feature->pivot->limit === null ? 'Unlimited' : $feature->pivot->limit }}
                                    @else
                                        Included
                                    @endif
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="rounded-lg border border-gray-200 bg-white p-6">
                <h2 class="mb-4 text-sm font-medium text-gray-500">Available plans</h2>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    @foreach ($plans as $plan)
                        @php
                            $isCurrent = $business->plan_id === $plan->id;
                            $isPaid = (float) $plan->price > 0;
                            $isUpgrade = ! $isCurrent && $business->plan && (float) $plan->price > (float) $business->plan->price;
                            $isDowngrade = ! $isCurrent && $business->plan && (float) $plan->price < (float) $business->plan->price;
                        @endphp
                        <div class="rounded-lg border border-gray-200 p-4 {{ $isCurrent ? 'bg-gray-50' : '' }}">
                            <p class="font-medium text-gray-900">{{ $plan->name }}</p>
                            <p class="text-sm text-gray-500">{{ $plan->formattedPrice() }} / month</p>

                            @if ($isCurrent)
                                <p class="mt-2 text-xs font-medium text-gray-500">Your current plan</p>
                                @if ($isPaid)
                                    <form method="POST" action="{{ route('subscription.payment.initiate') }}" class="mt-2">
                                        @csrf
                                        <input type="hidden" name="plan_id" value="{{ $plan->id }}">
                                        <button type="submit" class="w-full rounded-md bg-gray-900 px-3 py-1.5 text-xs text-white hover:bg-gray-700">
                                            Renew with Paystack
                                        </button>
                                    </form>
                                @endif
                            @elseif ($isPaid)
                                <form method="POST" action="{{ route('subscription.payment.initiate') }}" class="mt-2">
                                    @csrf
                                    <input type="hidden" name="plan_id" value="{{ $plan->id }}">
                                    <button type="submit" class="w-full rounded-md bg-gray-900 px-3 py-1.5 text-xs text-white hover:bg-gray-700">
                                        {{ $isUpgrade ? 'Upgrade' : ($isDowngrade ? 'Downgrade' : 'Pay') }} with Paystack
                                    </button>
                                </form>
                                @if ($isDowngrade)
                                    <p class="mt-1 text-center text-[11px] text-gray-400">Takes effect after your current period ends</p>
                                @endif
                                <p class="mt-1 text-center text-xs text-gray-400">or</p>
                                <form method="POST" action="{{ route('subscription.request') }}">
                                    @csrf
                                    <input type="hidden" name="plan_id" value="{{ $plan->id }}">
                                    <button type="submit" class="w-full rounded-md border border-gray-300 px-3 py-1.5 text-xs text-gray-700 hover:bg-gray-50"
                                        @disabled($pendingRequest && $pendingRequest->plan_id === $plan->id)>
                                        @if ($pendingRequest && $pendingRequest->plan_id === $plan->id)
                                            Requested
                                        @else
                                            Request review instead
                                        @endif
                                    </button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('subscription.request') }}" class="mt-2">
                                    @csrf
                                    <input type="hidden" name="plan_id" value="{{ $plan->id }}">
                                    <button type="submit" class="w-full rounded-md border border-gray-300 px-3 py-1.5 text-xs text-gray-700 hover:bg-gray-50"
                                        @disabled($pendingRequest && $pendingRequest->plan_id === $plan->id)>
                                        @if ($pendingRequest && $pendingRequest->plan_id === $plan->id)
                                            Requested
                                        @else
                                            Request this plan
                                        @endif
                                    </button>
                                </form>
                            @endif
                        </div>
                    @endforeach
                </div>
                <p class="mt-3 text-xs text-gray-500">
                    Paying with Paystack activates a renewal or upgrade as soon as payment is confirmed. A downgrade is paid for now
                    but only takes effect once your current paid period ends — you keep your current plan until then. Requesting a
                    plan instead does not change anything immediately — a platform admin reviews every such request.
                </p>
            </div>

            @if ($payments->isNotEmpty())
                <div class="rounded-lg border border-gray-200 bg-white p-6">
                    <h2 class="mb-4 text-sm font-medium text-gray-500">Payment history</h2>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="text-left text-gray-500">
                                <tr>
                                    <th class="py-2 pr-4 font-medium">Reference</th>
                                    <th class="py-2 pr-4 font-medium">Plan</th>
                                    <th class="py-2 pr-4 font-medium">Amount</th>
                                    <th class="py-2 pr-4 font-medium">Status</th>
                                    <th class="py-2 font-medium">Date</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                @foreach ($payments as $payment)
                                    <tr>
                                        <td class="py-2 pr-4 text-gray-900">{{ $payment->reference }}</td>
                                        <td class="py-2 pr-4 text-gray-500">{{ $payment->plan?->name ?? '—' }}</td>
                                        <td class="py-2 pr-4 text-gray-500">{{ $payment->formattedAmount() }}</td>
                                        <td class="py-2 pr-4 text-gray-500">{{ $payment->statusLabel() }}</td>
                                        <td class="py-2 text-gray-500">{{ $payment->created_at->format('d M Y, H:i') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </div>
    </div>
@endsection
