@extends('layouts.app')

@section('title', 'Categories — ' . config('app.name'))

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-semibold">Categories</h1>
        <a href="{{ route('categories.create') }}" class="rounded-md bg-gray-900 px-4 py-2 text-sm text-white hover:bg-gray-700">
            Add category
        </a>
    </div>

    @if (session('status'))
        <p class="mb-4 rounded-md bg-green-50 px-4 py-3 text-sm text-green-700">{{ session('status') }}</p>
    @endif

    @if ($categories->isEmpty())
        <p class="text-sm text-gray-500">No categories yet. Create a category to organize your products.</p>
    @else
        <div class="overflow-hidden rounded-lg border border-gray-200 bg-white">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-gray-500">
                    <tr>
                        <th class="px-4 py-3 font-medium">Name</th>
                        <th class="px-4 py-3 font-medium">Products</th>
                        <th class="px-4 py-3 font-medium"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @foreach ($categories as $category)
                        <tr>
                            <td class="px-4 py-3 text-gray-900">{{ $category->name }}</td>
                            <td class="px-4 py-3 text-gray-500">{{ $category->products_count }}</td>
                            <td class="px-4 py-3 text-right">
                                <a href="{{ route('categories.edit', $category) }}" class="text-gray-700 underline hover:text-gray-900">Edit</a>
                                <form method="POST" action="{{ route('categories.destroy', $category) }}" class="inline"
                                    onsubmit="return confirm('Delete this category? Its products will not be deleted.');">
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
