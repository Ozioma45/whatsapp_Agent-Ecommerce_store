@extends('layouts.admin')

@section('title', 'Users — ' . config('app.name'))

@section('content')
    <h1 class="mb-6 text-2xl font-semibold">Users</h1>

    <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-gray-500">
                <tr>
                    <th class="px-4 py-3 font-medium">Name</th>
                    <th class="px-4 py-3 font-medium">Email</th>
                    <th class="px-4 py-3 font-medium">Role</th>
                    <th class="px-4 py-3 font-medium">Business</th>
                    <th class="px-4 py-3 font-medium">Joined</th>
                    <th class="px-4 py-3 font-medium"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @foreach ($users as $user)
                    <tr>
                        <td class="px-4 py-3 text-gray-900">{{ $user->name }}</td>
                        <td class="px-4 py-3 text-gray-500">{{ $user->email }}</td>
                        <td class="px-4 py-3">
                            @if ($user->isAdmin())
                                <span class="rounded-full bg-blue-50 px-2 py-0.5 text-xs text-blue-700">Admin</span>
                            @else
                                <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600">Business owner</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-gray-500">{{ $user->business?->name ?? '—' }}</td>
                        <td class="px-4 py-3 text-gray-500">{{ $user->created_at->format('d M Y') }}</td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('admin.users.show', $user) }}" class="text-gray-700 underline hover:text-gray-900">View</a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $users->links() }}
    </div>
@endsection
