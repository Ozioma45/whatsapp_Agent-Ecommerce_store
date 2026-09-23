@extends('layouts.admin')

@section('title', 'Admin Dashboard — ' . config('app.name'))

@section('content')
    <h1 class="mb-6 text-2xl font-semibold">Platform overview</h1>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <div class="rounded-lg border border-gray-200 bg-white p-6">
            <p class="text-sm text-gray-500">Businesses</p>
            <p class="mt-1 text-3xl font-semibold text-gray-900">{{ $businessCount }}</p>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-6">
            <p class="text-sm text-gray-500">Users</p>
            <p class="mt-1 text-3xl font-semibold text-gray-900">{{ $userCount }}</p>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-6">
            <p class="text-sm text-gray-500">Orders</p>
            <p class="mt-1 text-3xl font-semibold text-gray-900">{{ $orderCount }}</p>
        </div>
    </div>

    <div class="mt-6 rounded-lg border border-gray-200 bg-white p-6">
        <h2 class="mb-4 text-sm font-medium text-gray-500">Businesses per plan</h2>
        <dl class="divide-y divide-gray-200">
            @foreach ($plans as $plan)
                <div class="flex items-center justify-between py-3">
                    <dt class="text-sm text-gray-700">{{ $plan->name }}</dt>
                    <dd class="text-sm font-medium text-gray-900">{{ $plan->businesses_count }}</dd>
                </div>
            @endforeach
        </dl>
    </div>
@endsection
