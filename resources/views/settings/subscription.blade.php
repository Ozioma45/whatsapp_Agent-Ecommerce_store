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
                <h2 class="mb-4 text-sm font-medium text-gray-500">Current plan</h2>
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
                        <dt class="text-gray-500">Start date</dt>
                        <dd class="text-gray-900">{{ $subscription?->starts_at?->format('d M Y') ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Expiry date</dt>
                        <dd class="text-gray-900">{{ $subscription?->expires_at?->format('d M Y') ?? 'Does not expire' }}</dd>
                    </div>
                </dl>

                @if ($pendingRequest)
                    <p class="mt-4 rounded-md bg-yellow-50 px-4 py-3 text-sm text-yellow-800">
                        A request to move to the <strong>{{ $pendingRequest->plan?->name ?? 'selected' }}</strong> plan is pending review.
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
                        <div class="rounded-lg border border-gray-200 p-4 {{ $business->plan_id === $plan->id ? 'bg-gray-50' : '' }}">
                            <p class="font-medium text-gray-900">{{ $plan->name }}</p>
                            <p class="text-sm text-gray-500">{{ $plan->formattedPrice() }} / month</p>
                            @if ($business->plan_id === $plan->id)
                                <p class="mt-2 text-xs font-medium text-gray-500">Your current plan</p>
                            @elseif ((float) $plan->price > 0)
                                <form method="POST" action="{{ route('subscription.payment.initiate') }}" class="mt-2">
                                    @csrf
                                    <input type="hidden" name="plan_id" value="{{ $plan->id }}">
                                    <button type="submit" class="w-full rounded-md bg-gray-900 px-3 py-1.5 text-xs text-white hover:bg-gray-700">
                                        Pay with Paystack
                                    </button>
                                </form>
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
                    Paying with Paystack activates your new plan as soon as payment is confirmed. Requesting a plan
                    instead does not change your plan immediately — a platform admin reviews every request.
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
