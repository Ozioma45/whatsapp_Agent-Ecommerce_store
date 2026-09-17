@extends('layouts.storefront')

@section('title', 'Cart — ' . $business->name)

@section('content')
    <h1 class="mb-6 text-2xl font-semibold">Your cart</h1>

    @if (session('error'))
        <p class="mb-6 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{{ session('error') }}</p>
    @endif

    @if ($errors->any())
        <p class="mb-6 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{{ $errors->first() }}</p>
    @endif

    @if ($itemsWereRemoved)
        <p class="mb-6 rounded-md bg-amber-50 px-4 py-3 text-sm text-amber-700">
            Some items in your cart are no longer available and have been removed.
        </p>
    @endif

    @if ($items->isEmpty())
        <div class="rounded-lg border border-gray-200 bg-white p-8 text-center">
            <p class="text-sm text-gray-500">Your cart is empty.</p>
            <a href="{{ route('store.show', ['business' => $business->handle]) }}"
                class="mt-4 inline-block rounded-md bg-gray-900 px-4 py-2 text-sm text-white hover:bg-gray-700">
                Continue shopping
            </a>
        </div>
    @else
        <div class="space-y-4">
            @foreach ($items as $item)
                @php $product = $item['product']; @endphp
                <div class="flex flex-wrap items-center gap-4 rounded-lg border border-gray-200 bg-white p-4">
                    @if ($product->image)
                        <img src="{{ \Illuminate\Support\Facades\Storage::url($product->image) }}" alt="{{ $product->name }}"
                            class="h-16 w-16 shrink-0 rounded-md object-cover">
                    @else
                        <div class="flex h-16 w-16 shrink-0 items-center justify-center rounded-md bg-gray-100 text-xs text-gray-400">
                            No image
                        </div>
                    @endif

                    <div class="min-w-0 flex-1">
                        <p class="font-medium text-gray-900">{{ $product->name }}</p>
                        <p class="text-sm text-gray-500">{{ number_format($product->price, 2) }} each</p>
                    </div>

                    <div class="flex items-center gap-2">
                        <form method="POST" action="{{ route('cart.update', ['business' => $business->handle, 'product' => $product->id]) }}">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="quantity" value="{{ $item['quantity'] - 1 }}">
                            <button type="submit" class="h-8 w-8 rounded-md border border-gray-300 text-gray-700 hover:bg-gray-50" aria-label="Decrease quantity">&minus;</button>
                        </form>

                        <span class="w-8 text-center text-sm text-gray-900">{{ $item['quantity'] }}</span>

                        @if ($item['quantity'] < \App\Support\Cart::MAX_QUANTITY)
                            <form method="POST" action="{{ route('cart.update', ['business' => $business->handle, 'product' => $product->id]) }}">
                                @csrf
                                @method('PATCH')
                                <input type="hidden" name="quantity" value="{{ $item['quantity'] + 1 }}">
                                <button type="submit" class="h-8 w-8 rounded-md border border-gray-300 text-gray-700 hover:bg-gray-50" aria-label="Increase quantity">&plus;</button>
                            </form>
                        @endif
                    </div>

                    <p class="w-24 shrink-0 text-right font-medium text-gray-900">{{ number_format($item['subtotal'], 2) }}</p>

                    <form method="POST" action="{{ route('cart.destroy', ['business' => $business->handle, 'product' => $product->id]) }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="text-sm text-red-600 underline hover:text-red-700">Remove</button>
                    </form>
                </div>
            @endforeach
        </div>

        <div class="mt-6 rounded-lg border border-gray-200 bg-white p-4">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <span class="text-lg font-semibold text-gray-900">Subtotal: {{ number_format($subtotal, 2) }}</span>

                <div class="flex items-center gap-4">
                    <a href="{{ route('store.show', ['business' => $business->handle]) }}" class="text-sm text-gray-700 underline hover:text-gray-900">
                        Continue shopping
                    </a>

                    <form method="POST" action="{{ route('cart.clear', ['business' => $business->handle]) }}"
                        onsubmit="return confirm('Clear your entire cart?');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="text-sm text-gray-500 underline hover:text-gray-700">Clear cart</button>
                    </form>
                </div>
            </div>

            <div class="mt-4 border-t border-gray-100 pt-4">
                @if ($hasWhatsappNumber)
                    <form method="POST" action="{{ route('cart.checkout', ['business' => $business->handle]) }}" class="space-y-3">
                        @csrf

                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div>
                                <label for="customer_name" class="block text-xs font-medium text-gray-500">Your name (optional)</label>
                                <input id="customer_name" type="text" name="customer_name" value="{{ old('customer_name') }}"
                                    class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-gray-500 focus:ring-gray-500">
                            </div>
                            <div>
                                <label for="customer_phone" class="block text-xs font-medium text-gray-500">Your WhatsApp number (optional)</label>
                                <input id="customer_phone" type="text" name="customer_phone" value="{{ old('customer_phone') }}"
                                    class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-gray-500 focus:ring-gray-500">
                            </div>
                        </div>

                        <button type="submit"
                            class="block w-full rounded-md bg-green-600 px-4 py-3 text-center font-medium text-white hover:bg-green-700 sm:w-auto">
                            Order via WhatsApp
                        </button>
                    </form>
                @else
                    <p class="text-sm text-gray-500">This store hasn't set up WhatsApp ordering yet.</p>
                @endif
            </div>
        </div>
    @endif
@endsection
