@extends('layouts.app')

@section('title', 'Dashboard — ' . config('app.name'))

@section('content')
    <h1 class="mb-6 text-2xl font-semibold">Dashboard</h1>

    <div class="max-w-xl rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
        <dl class="divide-y divide-gray-200">
            <div class="flex justify-between py-3">
                <dt class="text-sm font-medium text-gray-500">Business name</dt>
                <dd class="text-sm text-gray-900">{{ $business->name }}</dd>
            </div>
            <div class="flex justify-between py-3">
                <dt class="text-sm font-medium text-gray-500">Owner name</dt>
                <dd class="text-sm text-gray-900">{{ auth()->user()->name }}</dd>
            </div>
            <div class="flex justify-between py-3">
                <dt class="text-sm font-medium text-gray-500">Email</dt>
                <dd class="text-sm text-gray-900">{{ auth()->user()->email }}</dd>
            </div>
            <div class="flex justify-between py-3">
                <dt class="text-sm font-medium text-gray-500">Store handle</dt>
                <dd class="text-sm text-gray-900">{{ $business->handle }}</dd>
            </div>
            <div class="flex justify-between py-3">
                <dt class="text-sm font-medium text-gray-500">Store URL</dt>
                <dd class="text-sm text-gray-900">
                    <a href="{{ $business->publicUrl() }}" class="text-gray-900 underline" target="_blank">{{ $business->publicUrl() }}</a>
                </dd>
            </div>
            <div class="flex justify-between py-3">
                <dt class="text-sm font-medium text-gray-500">WhatsApp number</dt>
                <dd class="text-sm text-gray-900">{{ $business->setting?->whatsapp_number ?: 'Not set yet' }}</dd>
            </div>
        </dl>

        <div class="mt-6 flex items-center gap-2 rounded-md bg-green-50 px-4 py-3 text-sm text-green-700">
            <span>&#10003;</span>
            <span>Your store is set up. Manage its details in Store Settings.</span>
        </div>
    </div>
@endsection
