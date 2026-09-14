@extends('layouts.app')

@section('title', 'Add Product — ' . config('app.name'))

@section('content')
    <h1 class="mb-6 text-2xl font-semibold">Add product</h1>

    <div class="max-w-md rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
        <form method="POST" action="{{ route('products.store') }}" enctype="multipart/form-data" class="space-y-4">
            @include('products._form', ['product' => null, 'categories' => $categories])
        </form>
    </div>
@endsection
