@extends('layouts.storefront')

@section('title', $business->name . ' — ' . config('app.name'))

@section('content')
    @if (session('status'))
        <p class="mb-6 rounded-md bg-green-50 px-4 py-3 text-center text-sm text-green-700">{{ session('status') }}</p>
    @endif

    <div class="mb-10 text-center">
        <h1 class="text-3xl font-semibold">{{ $business->name }}</h1>

        @if ($business->setting?->description)
            <p class="mx-auto mt-3 max-w-xl text-gray-600">{{ $business->setting->description }}</p>
        @endif
    </div>

    @if ($categories->isNotEmpty())
        <nav class="mb-8 flex flex-wrap justify-center gap-2">
            <a href="{{ route('store.show', ['business' => $business->handle]) }}"
                class="rounded-full px-3 py-1 text-sm {{ $selectedCategory ? 'bg-gray-100 text-gray-700 hover:bg-gray-200' : 'bg-gray-900 text-white' }}">
                All
            </a>
            @foreach ($categories as $category)
                <a href="{{ route('store.show', ['business' => $business->handle, 'category' => $category->id]) }}"
                    class="rounded-full px-3 py-1 text-sm {{ $selectedCategory?->id === $category->id ? 'bg-gray-900 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' }}">
                    {{ $category->name }}
                </a>
            @endforeach
        </nav>
    @endif

    @if ($products->isEmpty())
        <p class="text-center text-sm text-gray-500">
            {{ $selectedCategory ? 'No products in this category yet.' : 'No products available yet.' }}
        </p>
    @else
        <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($products as $product)
                <div class="overflow-hidden rounded-lg border border-gray-200 bg-white">
                    @if ($product->image)
                        <img src="{{ \Illuminate\Support\Facades\Storage::url($product->image) }}" alt="{{ $product->name }}"
                            class="h-48 w-full object-cover">
                    @else
                        <div class="flex h-48 items-center justify-center bg-gray-100 text-sm text-gray-400">No image</div>
                    @endif

                    <div class="p-4">
                        <div class="flex items-start justify-between gap-2">
                            <h3 class="font-medium text-gray-900">{{ $product->name }}</h3>
                            <span class="shrink-0 rounded-full bg-green-50 px-2 py-0.5 text-xs text-green-700">Available</span>
                        </div>

                        @if ($product->category)
                            <p class="mt-1 text-xs text-gray-500">{{ $product->category->name }}</p>
                        @endif

                        @if ($product->description)
                            <p class="mt-2 text-sm text-gray-600">{{ $product->description }}</p>
                        @endif

                        <div class="mt-3 flex items-center justify-between gap-2">
                            <p class="font-semibold text-gray-900">{{ number_format($product->price, 2) }}</p>

                            <form method="POST" action="{{ route('cart.store', ['business' => $business->handle, 'product' => $product->id]) }}">
                                @csrf
                                <button type="submit" class="rounded-md bg-gray-900 px-3 py-1.5 text-sm text-white hover:bg-gray-700">
                                    Add to Cart
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
@endsection
