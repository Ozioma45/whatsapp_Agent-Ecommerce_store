@extends('layouts.admin')

@section('title', $business->name . ' — ' . config('app.name'))

@section('content')
    <div class="mb-6 flex flex-wrap items-center justify-between gap-2">
        <h1 class="text-2xl font-semibold">{{ $business->name }}</h1>
        <a href="{{ route('admin.businesses.index') }}" class="text-sm text-gray-500 underline hover:text-gray-700">Back to businesses</a>
    </div>

    @if (session('status'))
        <p class="mb-4 rounded-md bg-green-50 px-4 py-3 text-sm text-green-700">{{ session('status') }}</p>
    @endif
    @if (session('error'))
        <p class="mb-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{{ session('error') }}</p>
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <div class="rounded-lg border border-gray-200 bg-white p-6">
                <h2 class="mb-4 text-sm font-medium text-gray-500">Business</h2>
                <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-gray-500">Handle</dt>
                        <dd class="text-gray-900">{{ $business->handle }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Store URL</dt>
                        <dd class="text-gray-900"><a href="{{ $business->publicUrl() }}" class="underline" target="_blank">{{ $business->publicUrl() }}</a></dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Created</dt>
                        <dd class="text-gray-900">{{ $business->created_at->format('d M Y, H:i') }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Current plan</dt>
                        <dd class="text-gray-900">{{ $business->plan?->name ?? 'None' }}</dd>
                    </div>
                </dl>
            </div>

            <div class="rounded-lg border border-gray-200 bg-white p-6">
                <h2 class="mb-4 text-sm font-medium text-gray-500">Owner</h2>
                <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-gray-500">Name</dt>
                        <dd class="text-gray-900">{{ $business->owner->name }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Email</dt>
                        <dd class="text-gray-900">{{ $business->owner->email }}</dd>
                    </div>
                </dl>
            </div>

            <div class="rounded-lg border border-gray-200 bg-white p-6">
                <h2 class="mb-4 text-sm font-medium text-gray-500">Store settings</h2>
                <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-gray-500">Description</dt>
                        <dd class="text-gray-900">{{ $business->setting?->description ?: 'Not set' }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">WhatsApp number</dt>
                        <dd class="text-gray-900">{{ $business->setting?->whatsapp_number ?: 'Not set' }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Logo</dt>
                        <dd class="text-gray-900">{{ $business->setting?->logo ? 'Uploaded' : 'Not set' }}</dd>
                    </div>
                </dl>
            </div>

            <div class="rounded-lg border border-gray-200 bg-white p-6">
                <h2 class="mb-4 text-sm font-medium text-gray-500">Statistics</h2>
                <dl class="grid grid-cols-3 gap-3 text-sm">
                    <div>
                        <dt class="text-gray-500">Products</dt>
                        <dd class="text-lg font-semibold text-gray-900">{{ $productCount }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Categories</dt>
                        <dd class="text-lg font-semibold text-gray-900">{{ $categoryCount }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Orders</dt>
                        <dd class="text-lg font-semibold text-gray-900">{{ $orderCount }}</dd>
                    </div>
                </dl>
            </div>
        </div>

        <div>
            <div class="rounded-lg border border-gray-200 bg-white p-6">
                <h2 class="mb-4 text-sm font-medium text-gray-500">Change plan</h2>
                <form method="POST" action="{{ route('admin.businesses.updatePlan', $business) }}" class="space-y-3">
                    @csrf
                    @method('PATCH')

                    <select name="plan_id" class="block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-gray-500 focus:ring-gray-500">
                        @foreach ($plans as $plan)
                            <option value="{{ $plan->id }}" @selected($business->plan_id === $plan->id)>{{ $plan->name }}</option>
                        @endforeach
                    </select>
                    @error('plan_id')
                        <p class="text-sm text-red-600">{{ $message }}</p>
                    @enderror

                    <button type="submit" class="w-full rounded-md bg-gray-900 px-4 py-2 text-sm text-white hover:bg-gray-700">
                        Save plan
                    </button>
                </form>
                <p class="mt-3 text-xs text-gray-500">This is a manual assignment — there is no billing yet.</p>
            </div>
        </div>
    </div>
@endsection
