@extends('layouts.admin')

@section('title', 'Features — ' . config('app.name'))

@section('content')
    <h1 class="mb-6 text-2xl font-semibold">Features</h1>

    @if (session('status'))
        <p class="mb-4 rounded-md bg-green-50 px-4 py-3 text-sm text-green-700">{{ session('status') }}</p>
    @endif

    <p class="mb-6 max-w-2xl text-sm text-gray-500">
        Features are enabled and limited per plan from each plan's page. Only the display name and
        description can be edited here — the key and type are fixed, since application code depends on them.
    </p>

    <div class="space-y-4">
        @foreach ($features as $feature)
            <div class="rounded-lg border border-gray-200 bg-white p-6">
                <form method="POST" action="{{ route('admin.features.update', $feature) }}" class="space-y-3">
                    @csrf
                    @method('PATCH')

                    <div class="flex flex-wrap items-center gap-2">
                        <p class="font-mono text-xs text-gray-400">{{ $feature->key }}</p>
                        <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600">{{ ucfirst($feature->type) }}</span>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-500">Name</label>
                        <input type="text" name="name" value="{{ old('name', $feature->name) }}" required
                            class="mt-1 block w-full max-w-sm rounded-md border-gray-300 text-sm shadow-sm focus:border-gray-500 focus:ring-gray-500">
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-500">Description</label>
                        <input type="text" name="description" value="{{ old('description', $feature->description) }}"
                            class="mt-1 block w-full max-w-lg rounded-md border-gray-300 text-sm shadow-sm focus:border-gray-500 focus:ring-gray-500">
                    </div>

                    <button type="submit" class="rounded-md bg-gray-900 px-3 py-1.5 text-xs text-white hover:bg-gray-700">
                        Save
                    </button>
                </form>
            </div>
        @endforeach
    </div>
@endsection
