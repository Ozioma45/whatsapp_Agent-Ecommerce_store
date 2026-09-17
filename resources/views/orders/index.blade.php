@extends('layouts.app')

@section('title', 'Orders — ' . config('app.name'))

@section('content')
    <h1 class="mb-6 text-2xl font-semibold">Orders</h1>

    @if (session('status'))
        <p class="mb-4 rounded-md bg-green-50 px-4 py-3 text-sm text-green-700">{{ session('status') }}</p>
    @endif

    @if ($orders->isEmpty())
        <p class="text-sm text-gray-500">No orders yet.</p>
    @else
        <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-gray-500">
                    <tr>
                        <th class="px-4 py-3 font-medium">Order #</th>
                        <th class="px-4 py-3 font-medium">Customer</th>
                        <th class="px-4 py-3 font-medium">Phone</th>
                        <th class="px-4 py-3 font-medium">Total</th>
                        <th class="px-4 py-3 font-medium">Status</th>
                        <th class="px-4 py-3 font-medium">Date</th>
                        <th class="px-4 py-3 font-medium"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @foreach ($orders as $order)
                        <tr>
                            <td class="px-4 py-3 font-medium text-gray-900">{{ $order->order_number }}</td>
                            <td class="px-4 py-3 text-gray-500">{{ $order->customer_name ?: '—' }}</td>
                            <td class="px-4 py-3 text-gray-500">{{ $order->customer_phone ?: '—' }}</td>
                            <td class="px-4 py-3 text-gray-500">{{ number_format($order->total, 2) }}</td>
                            <td class="px-4 py-3">
                                @include('orders._status-badge', ['status' => $order->status])
                            </td>
                            <td class="px-4 py-3 text-gray-500">{{ $order->created_at->format('d M Y, H:i') }}</td>
                            <td class="px-4 py-3 text-right">
                                <a href="{{ route('orders.show', $order) }}" class="text-gray-700 underline hover:text-gray-900">View</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection
