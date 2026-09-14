@extends('layouts.app')

@section('title', $business->name . ' — ' . config('app.name'))

@section('content')
    <div class="mx-auto max-w-xl text-center">
        @if ($business->setting?->logo)
            <img src="{{ \Illuminate\Support\Facades\Storage::url($business->setting->logo) }}" alt="{{ $business->name }} logo"
                class="mx-auto mb-4 h-24 w-24 rounded-full object-cover">
        @endif

        <h1 class="text-3xl font-semibold">{{ $business->name }}</h1>

        @if ($business->setting?->description)
            <p class="mt-4 text-gray-600">{{ $business->setting->description }}</p>
        @endif

        @if ($business->setting?->whatsapp_number)
            <p class="mt-6 text-sm text-gray-500">
                WhatsApp: <span class="font-medium text-gray-900">{{ $business->setting->whatsapp_number }}</span>
            </p>
        @endif
    </div>
@endsection
