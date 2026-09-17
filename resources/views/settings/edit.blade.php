@extends('layouts.app')

@section('title', 'Store Settings — ' . config('app.name'))

@section('content')
    <h1 class="mb-6 text-2xl font-semibold">Store Settings</h1>

    @if (session('status'))
        <p class="mb-4 rounded-md bg-green-50 px-4 py-3 text-sm text-green-700">{{ session('status') }}</p>
    @endif

    <div class="max-w-xl rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
        <form method="POST" action="{{ route('settings.update') }}" enctype="multipart/form-data" class="space-y-4">
            @csrf
            @method('PUT')

            <div>
                <label class="block text-sm font-medium text-gray-700">Store handle</label>
                <p class="mt-1 text-sm text-gray-500">{{ $business->handle }} (cannot be changed here)</p>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">Your store</label>
                <p class="mt-1 text-sm text-gray-500">
                    <a href="{{ $business->publicUrl() }}" class="text-gray-900 underline" target="_blank">{{ $business->publicUrl() }}</a>
                </p>
            </div>

            <div>
                <label for="name" class="block text-sm font-medium text-gray-700">Business name</label>
                <input id="name" type="text" name="name" value="{{ old('name', $business->name) }}" required
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-gray-500 focus:ring-gray-500">
                @error('name')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="description" class="block text-sm font-medium text-gray-700">Description</label>
                <textarea id="description" name="description" rows="3"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-gray-500 focus:ring-gray-500">{{ old('description', $business->setting?->description) }}</textarea>
                @error('description')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="whatsapp_number" class="block text-sm font-medium text-gray-700">WhatsApp number</label>
                <input id="whatsapp_number" type="text" name="whatsapp_number"
                    value="{{ old('whatsapp_number', $business->setting?->whatsapp_number) }}"
                    placeholder="e.g. +2348012345678"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-gray-500 focus:ring-gray-500">
                @error('whatsapp_number')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">Logo</label>

                @if ($business->setting?->logo)
                    <div class="mt-2 flex h-20 w-20 items-center justify-center overflow-hidden rounded-md bg-gray-100">
                        <img src="{{ \Illuminate\Support\Facades\Storage::url($business->setting->logo) }}" alt="Current logo"
                            class="h-full w-full object-contain">
                    </div>
                @endif

                <input type="file" name="logo" accept="image/*"
                    class="mt-2 block w-full text-sm text-gray-700">
                <p class="mt-1 text-xs text-gray-500">JPG, PNG or WEBP, up to 2MB.</p>
                @error('logo')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <button type="submit" class="w-full rounded-md bg-gray-900 px-4 py-2 text-white hover:bg-gray-700">
                Save changes
            </button>
        </form>
    </div>
@endsection
