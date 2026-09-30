@extends('layouts.admin')

@section('title', $plan->name . ' — ' . config('app.name'))

@section('content')
    <div class="mb-6 flex flex-wrap items-center justify-between gap-2">
        <h1 class="text-2xl font-semibold">{{ $plan->name }}</h1>
        <a href="{{ route('admin.plans.index') }}" class="text-sm text-gray-500 underline hover:text-gray-700">Back to plans</a>
    </div>

    @if (session('status'))
        <p class="mb-4 rounded-md bg-green-50 px-4 py-3 text-sm text-green-700">{{ session('status') }}</p>
    @endif
    @if (session('error'))
        <p class="mb-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{{ session('error') }}</p>
    @endif

    <form method="POST" action="{{ route('admin.plans.update', $plan) }}" class="space-y-6">
        @csrf
        @method('PATCH')

        <div class="rounded-lg border border-gray-200 bg-white p-6">
            <h2 class="mb-4 text-sm font-medium text-gray-500">Plan details</h2>

            <div class="space-y-4">
                <div>
                    <label for="name" class="block text-sm font-medium text-gray-700">Name</label>
                    <input id="name" type="text" name="name" value="{{ old('name', $plan->name) }}" required
                        class="mt-1 block w-full max-w-sm rounded-md border-gray-300 shadow-sm focus:border-gray-500 focus:ring-gray-500">
                    @error('name')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">Slug</label>
                    <p class="mt-1 text-sm text-gray-500">{{ $plan->slug }} (cannot be changed here)</p>
                </div>

                <div>
                    <label for="description" class="block text-sm font-medium text-gray-700">Description</label>
                    <textarea id="description" name="description" rows="2"
                        class="mt-1 block w-full max-w-lg rounded-md border-gray-300 shadow-sm focus:border-gray-500 focus:ring-gray-500">{{ old('description', $plan->description) }}</textarea>
                </div>

                <div>
                    <label for="price" class="block text-sm font-medium text-gray-700">Price (₦)</label>
                    <input id="price" type="number" step="0.01" min="0" name="price" value="{{ old('price', $plan->price) }}" required
                        class="mt-1 block w-full max-w-xs rounded-md border-gray-300 shadow-sm focus:border-gray-500 focus:ring-gray-500">
                    <p class="mt-1 text-xs text-gray-500">Shown to business owners on their subscription page. This never changes the plan's features or any business's current subscription.</p>
                    @error('price')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <label class="flex items-center gap-2 text-sm text-gray-700">
                    <input type="checkbox" name="is_active" value="1" class="rounded border-gray-300" @checked(old('is_active', $plan->is_active))>
                    Active (can be assigned to businesses)
                </label>
            </div>
        </div>

        <div class="rounded-lg border border-gray-200 bg-white p-6">
            <h2 class="mb-4 text-sm font-medium text-gray-500">Feature entitlements</h2>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="text-left text-gray-500">
                        <tr>
                            <th class="py-2 pr-4 font-medium">Feature</th>
                            <th class="py-2 pr-4 font-medium">Enabled</th>
                            <th class="py-2 font-medium">Limit</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @foreach ($plan->features as $feature)
                            <tr>
                                <td class="py-3 pr-4 text-gray-900">{{ $feature->name }}</td>
                                <td class="py-3 pr-4">
                                    <input type="checkbox" name="features[{{ $feature->id }}][enabled]" value="1"
                                        class="rounded border-gray-300" @checked($feature->pivot->enabled)>
                                </td>
                                <td class="py-3">
                                    @if ($feature->type === \App\Models\Feature::TYPE_LIMIT)
                                        <input type="number" min="0" name="features[{{ $feature->id }}][limit]"
                                            value="{{ $feature->pivot->limit }}" placeholder="Unlimited"
                                            class="block w-32 rounded-md border-gray-300 text-sm shadow-sm focus:border-gray-500 focus:ring-gray-500">
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="mt-3 text-xs text-gray-500">Leave a limit blank for unlimited.</p>
        </div>

        <button type="submit" class="rounded-md bg-gray-900 px-4 py-2 text-sm text-white hover:bg-gray-700">
            Save plan
        </button>
    </form>

    @if ($plan->businesses()->count() === 0)
        <form method="POST" action="{{ route('admin.plans.destroy', $plan) }}" class="mt-6"
            onsubmit="return confirm('Delete this plan? This cannot be undone.');">
            @csrf
            @method('DELETE')
            <button type="submit" class="text-sm text-red-600 underline hover:text-red-700">Delete this plan</button>
        </form>
    @else
        <p class="mt-6 text-xs text-gray-400">This plan cannot be deleted while businesses are assigned to it.</p>
    @endif
@endsection
