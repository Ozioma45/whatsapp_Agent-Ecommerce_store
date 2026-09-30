@extends('layouts.admin')

@section('title', 'Payments — ' . config('app.name'))

@section('content')
    <h1 class="mb-6 text-2xl font-semibold">Payments</h1>

    @if ($payments->isEmpty())
        <p class="text-sm text-gray-500">No payment transactions yet.</p>
    @else
        <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-gray-500">
                    <tr>
                        <th class="px-4 py-3 font-medium">Reference</th>
                        <th class="px-4 py-3 font-medium">Business</th>
                        <th class="px-4 py-3 font-medium">Plan</th>
                        <th class="px-4 py-3 font-medium">Amount</th>
                        <th class="px-4 py-3 font-medium">Status</th>
                        <th class="px-4 py-3 font-medium">Date</th>
                        <th class="px-4 py-3 font-medium"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @foreach ($payments as $payment)
                        <tr>
                            <td class="px-4 py-3 font-medium text-gray-900">{{ $payment->reference }}</td>
                            <td class="px-4 py-3 text-gray-500">{{ $payment->business?->name ?? '—' }}</td>
                            <td class="px-4 py-3 text-gray-500">{{ $payment->plan?->name ?? '—' }}</td>
                            <td class="px-4 py-3 text-gray-500">{{ $payment->formattedAmount() }}</td>
                            <td class="px-4 py-3">
                                @php
                                    $badgeColor = match ($payment->status) {
                                        \App\Models\PaymentTransaction::STATUS_SUCCESSFUL => 'bg-green-50 text-green-700',
                                        \App\Models\PaymentTransaction::STATUS_PENDING => 'bg-yellow-50 text-yellow-800',
                                        default => 'bg-red-50 text-red-700',
                                    };
                                @endphp
                                <span class="rounded-full {{ $badgeColor }} px-2 py-0.5 text-xs">{{ $payment->statusLabel() }}</span>
                            </td>
                            <td class="px-4 py-3 text-gray-500">{{ $payment->created_at->format('d M Y, H:i') }}</td>
                            <td class="px-4 py-3 text-right">
                                <a href="{{ route('admin.payments.show', $payment) }}" class="text-gray-700 underline hover:text-gray-900">View</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-4">{{ $payments->links() }}</div>
    @endif
@endsection
