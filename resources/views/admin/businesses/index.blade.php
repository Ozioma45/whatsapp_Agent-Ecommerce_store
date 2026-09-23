@extends('layouts.admin')

@section('title', 'Businesses — ' . config('app.name'))

@section('content')
    <h1 class="mb-6 text-2xl font-semibold">Businesses</h1>

    <form method="GET" action="{{ route('admin.businesses.index') }}" class="mb-4">
        <input type="text" name="search" value="{{ $search }}" placeholder="Search by name, handle, or owner email"
            class="w-full max-w-md rounded-md border-gray-300 text-sm shadow-sm focus:border-gray-500 focus:ring-gray-500">
    </form>

    @if ($businesses->isEmpty())
        <p class="text-sm text-gray-500">No businesses found.</p>
    @else
        <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-gray-500">
                    <tr>
                        <th class="px-4 py-3 font-medium">Business</th>
                        <th class="px-4 py-3 font-medium">Handle</th>
                        <th class="px-4 py-3 font-medium">Owner</th>
                        <th class="px-4 py-3 font-medium">Plan</th>
                        <th class="px-4 py-3 font-medium">Created</th>
                        <th class="px-4 py-3 font-medium"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @foreach ($businesses as $business)
                        <tr>
                            <td class="px-4 py-3 text-gray-900">{{ $business->name }}</td>
                            <td class="px-4 py-3 text-gray-500">{{ $business->handle }}</td>
                            <td class="px-4 py-3 text-gray-500">
                                {{ $business->owner->name }}<br>
                                <span class="text-xs text-gray-400">{{ $business->owner->email }}</span>
                            </td>
                            <td class="px-4 py-3 text-gray-500">{{ $business->plan?->name ?? '—' }}</td>
                            <td class="px-4 py-3 text-gray-500">{{ $business->created_at->format('d M Y') }}</td>
                            <td class="px-4 py-3 text-right">
                                <a href="{{ route('admin.businesses.show', $business) }}" class="text-gray-700 underline hover:text-gray-900">View</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $businesses->links() }}
        </div>
    @endif
@endsection
