@extends('layouts.app')

@section('title', $order->order_number . ' — ' . config('app.name'))

@section('content')
    <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
        <h1 class="text-2xl font-semibold">Order {{ $order->order_number }}</h1>
        @include('orders._status-badge', ['status' => $order->status])
    </div>

    @if (session('status'))
        <p class="mb-4 rounded-md bg-green-50 px-4 py-3 text-sm text-green-700">{{ session('status') }}</p>
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2 space-y-6">
            <div class="rounded-lg border border-gray-200 bg-white p-6">
                <h2 class="mb-4 text-sm font-medium text-gray-500">Items</h2>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="text-left text-gray-500">
                            <tr>
                                <th class="py-2 pr-4 font-medium">Product</th>
                                <th class="py-2 pr-4 font-medium">Qty</th>
                                <th class="py-2 pr-4 font-medium">Unit price</th>
                                <th class="py-2 font-medium text-right">Subtotal</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            @foreach ($order->items as $item)
                                <tr>
                                    <td class="py-3 pr-4 text-gray-900">{{ $item->product_name }}</td>
                                    <td class="py-3 pr-4 text-gray-500">{{ $item->quantity }}</td>
                                    <td class="py-3 pr-4 text-gray-500">{{ number_format($item->unit_price, 2) }}</td>
                                    <td class="py-3 text-right font-medium text-gray-900">{{ number_format($item->subtotal, 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="mt-4 space-y-1 border-t border-gray-100 pt-4 text-right text-sm">
                    <p class="text-gray-500">Subtotal: <span class="text-gray-900">{{ number_format($order->subtotal, 2) }}</span></p>
                    <p class="text-base font-semibold text-gray-900">Total: {{ number_format($order->total, 2) }}</p>
                </div>
            </div>
        </div>

        <div class="space-y-6">
            <div class="rounded-lg border border-gray-200 bg-white p-6">
                <h2 class="mb-4 text-sm font-medium text-gray-500">Order information</h2>
                <dl class="space-y-3 text-sm">
                    <div>
                        <dt class="text-gray-500">Date</dt>
                        <dd class="text-gray-900">{{ $order->created_at->format('d M Y, H:i') }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Customer name</dt>
                        <dd class="text-gray-900">{{ $order->customer_name ?: 'Not provided' }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Customer phone</dt>
                        <dd class="text-gray-900">{{ $order->customer_phone ?: 'Not provided' }}</dd>
                    </div>
                </dl>
            </div>

            <div class="rounded-lg border border-gray-200 bg-white p-6">
                <h2 class="mb-4 text-sm font-medium text-gray-500">Update status</h2>
                <form method="POST" action="{{ route('orders.update', $order) }}" class="space-y-3">
                    @csrf
                    @method('PUT')

                    <select name="status" class="block w-full rounded-md border-gray-300 shadow-sm focus:border-gray-500 focus:ring-gray-500">
                        @foreach (\App\Models\Order::STATUSES as $status)
                            <option value="{{ $status }}" @selected($order->status === $status)>{{ ucfirst($status) }}</option>
                        @endforeach
                    </select>
                    @error('status')
                        <p class="text-sm text-red-600">{{ $message }}</p>
                    @enderror

                    <button type="submit" class="w-full rounded-md bg-gray-900 px-4 py-2 text-sm text-white hover:bg-gray-700">
                        Save status
                    </button>
                </form>
            </div>
        </div>
    </div>
@endsection
