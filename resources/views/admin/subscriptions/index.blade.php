@extends('layouts.admin')

@section('title', 'Subscription requests — ' . config('app.name'))

@section('content')
    <h1 class="mb-6 text-2xl font-semibold">Subscription requests</h1>

    @if (session('status'))
        <p class="mb-4 rounded-md bg-green-50 px-4 py-3 text-sm text-green-700">{{ session('status') }}</p>
    @endif
    @if (session('error'))
        <p class="mb-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{{ session('error') }}</p>
    @endif

    @if ($pendingRequests->isEmpty())
        <p class="text-sm text-gray-500">No pending requests.</p>
    @else
        <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-gray-500">
                    <tr>
                        <th class="px-4 py-3 font-medium">Business</th>
                        <th class="px-4 py-3 font-medium">Requested plan</th>
                        <th class="px-4 py-3 font-medium">Requested</th>
                        <th class="px-4 py-3 font-medium"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @foreach ($pendingRequests as $request)
                        <tr>
                            <td class="px-4 py-3 text-gray-900">
                                <a href="{{ route('admin.businesses.show', $request->business->handle) }}" class="underline hover:text-gray-700">
                                    {{ $request->business->name }}
                                </a>
                            </td>
                            <td class="px-4 py-3 text-gray-900">{{ $request->plan?->name ?? 'Unknown plan' }}</td>
                            <td class="px-4 py-3 text-gray-500">{{ $request->requested_at?->format('d M Y, H:i') }}</td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex flex-wrap items-center justify-end gap-3">
                                    <form method="POST" action="{{ route('admin.subscriptions.approve', $request) }}" class="flex items-center gap-1">
                                        @csrf
                                        @method('PATCH')
                                        <input type="date" name="expires_at" class="rounded-md border-gray-300 text-xs shadow-sm focus:border-gray-500 focus:ring-gray-500" placeholder="Expiry (optional)">
                                        <button type="submit" class="rounded-md bg-gray-900 px-3 py-1.5 text-xs text-white hover:bg-gray-700">Approve</button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.subscriptions.reject', $request) }}">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" class="text-xs text-red-600 underline hover:text-red-700">Reject</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection
