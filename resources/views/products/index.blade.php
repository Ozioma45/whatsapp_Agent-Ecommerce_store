@extends('layouts.app')

@section('title', 'Products — ' . config('app.name'))

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-semibold">Products</h1>
        <a href="{{ route('products.create') }}" class="rounded-md bg-gray-900 px-4 py-2 text-sm text-white hover:bg-gray-700">
            Add product
        </a>
    </div>

    @if (session('status'))
        <p class="mb-4 rounded-md bg-green-50 px-4 py-3 text-sm text-green-700">{{ session('status') }}</p>
    @endif

    @if ($products->isEmpty())
        <p class="text-sm text-gray-500">No products yet. Add your first product.</p>
    @else
        <div class="overflow-hidden rounded-lg border border-gray-200 bg-white">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-gray-500">
                    <tr>
                        <th class="px-4 py-3 font-medium">Image</th>
                        <th class="px-4 py-3 font-medium">Name</th>
                        <th class="px-4 py-3 font-medium">Category</th>
                        <th class="px-4 py-3 font-medium">Price</th>
                        <th class="px-4 py-3 font-medium">Availability</th>
                        <th class="px-4 py-3 font-medium"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @foreach ($products as $product)
                        <tr>
                            <td class="px-4 py-3">
                                @if ($product->image)
                                    <img src="{{ \Illuminate\Support\Facades\Storage::url($product->image) }}" alt="{{ $product->name }}"
                                        class="h-10 w-10 rounded-md object-cover">
                                @else
                                    <div class="h-10 w-10 rounded-md bg-gray-100"></div>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-gray-900">{{ $product->name }}</td>
                            <td class="px-4 py-3 text-gray-500">{{ $product->category?->name ?? '—' }}</td>
                            <td class="px-4 py-3 text-gray-500">{{ number_format($product->price, 2) }}</td>
                            <td class="px-4 py-3">
                                @if ($product->is_available)
                                    <span class="rounded-full bg-green-50 px-2 py-0.5 text-xs text-green-700">Available</span>
                                @else
                                    <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600">Unavailable</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                <a href="{{ route('products.edit', $product) }}" class="text-gray-700 underline hover:text-gray-900">Edit</a>
                                <form method="POST" action="{{ route('products.destroy', $product) }}" class="inline"
                                    onsubmit="return confirm('Delete this product?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="ml-3 text-red-600 underline hover:text-red-700">Delete</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection
