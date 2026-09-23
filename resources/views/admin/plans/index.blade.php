@extends('layouts.admin')

@section('title', 'Plans — ' . config('app.name'))

@section('content')
    <h1 class="mb-6 text-2xl font-semibold">Plans</h1>

    <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-gray-500">
                <tr>
                    <th class="px-4 py-3 font-medium">Plan</th>
                    <th class="px-4 py-3 font-medium">Status</th>
                    <th class="px-4 py-3 font-medium">Businesses</th>
                    <th class="px-4 py-3 font-medium"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @foreach ($plans as $plan)
                    <tr>
                        <td class="px-4 py-3 text-gray-900">{{ $plan->name }}</td>
                        <td class="px-4 py-3">
                            @if ($plan->is_active)
                                <span class="rounded-full bg-green-50 px-2 py-0.5 text-xs text-green-700">Active</span>
                            @else
                                <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600">Inactive</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-gray-500">{{ $plan->businesses_count }}</td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('admin.plans.show', $plan) }}" class="text-gray-700 underline hover:text-gray-900">Manage</a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endsection
