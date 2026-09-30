@extends('layouts.admin')

@section('title', $payment->reference . ' — ' . config('app.name'))

@section('content')
    <div class="mb-6 flex flex-wrap items-center justify-between gap-2">
        <h1 class="text-2xl font-semibold">{{ $payment->reference }}</h1>
        <a href="{{ route('admin.payments.index') }}" class="text-sm text-gray-500 underline hover:text-gray-700">Back to payments</a>
    </div>

    <div class="rounded-lg border border-gray-200 bg-white p-6">
        <dl class="grid grid-cols-1 gap-4 text-sm sm:grid-cols-2">
            <div>
                <dt class="text-gray-500">Business</dt>
                <dd class="text-gray-900">
                    @if ($payment->business)
                        <a href="{{ route('admin.businesses.show', $payment->business->handle) }}" class="underline">{{ $payment->business->name }}</a>
                    @else
                        —
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-gray-500">Plan</dt>
                <dd class="text-gray-900">{{ $payment->plan?->name ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-gray-500">Amount</dt>
                <dd class="text-gray-900">{{ $payment->formattedAmount() }}</dd>
            </div>
            <div>
                <dt class="text-gray-500">Currency</dt>
                <dd class="text-gray-900">{{ $payment->currency }}</dd>
            </div>
            <div>
                <dt class="text-gray-500">Status</dt>
                <dd class="text-gray-900">{{ $payment->statusLabel() }}</dd>
            </div>
            <div>
                <dt class="text-gray-500">Channel</dt>
                <dd class="text-gray-900">{{ $payment->channel ?: 'Not yet known' }}</dd>
            </div>
            <div>
                <dt class="text-gray-500">Paystack transaction ID</dt>
                <dd class="text-gray-900">{{ $payment->paystack_transaction_id ?: 'Not yet available' }}</dd>
            </div>
            <div>
                <dt class="text-gray-500">Failure reason</dt>
                <dd class="text-gray-900">{{ $payment->failure_reason ?: '—' }}</dd>
            </div>
            <div>
                <dt class="text-gray-500">Initialized</dt>
                <dd class="text-gray-900">{{ $payment->initialized_at?->format('d M Y, H:i') ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-gray-500">Verified</dt>
                <dd class="text-gray-900">{{ $payment->verified_at?->format('d M Y, H:i') ?? 'Not yet verified' }}</dd>
            </div>
            <div>
                <dt class="text-gray-500">Subscription request</dt>
                <dd class="text-gray-900">{{ $payment->subscription?->statusLabel() ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-gray-500">Created</dt>
                <dd class="text-gray-900">{{ $payment->created_at->format('d M Y, H:i') }}</dd>
            </div>
        </dl>
    </div>
@endsection
