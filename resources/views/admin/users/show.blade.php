@extends('layouts.admin')

@section('title', $user->name . ' — ' . config('app.name'))

@section('content')
    <div class="mb-6 flex flex-wrap items-center justify-between gap-2">
        <h1 class="text-2xl font-semibold">{{ $user->name }}</h1>
        <a href="{{ route('admin.users.index') }}" class="text-sm text-gray-500 underline hover:text-gray-700">Back to users</a>
    </div>

    @if (session('status'))
        <p class="mb-4 rounded-md bg-green-50 px-4 py-3 text-sm text-green-700">{{ session('status') }}</p>
    @endif
    @if (session('error'))
        <p class="mb-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{{ session('error') }}</p>
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <div class="rounded-lg border border-gray-200 bg-white p-6">
            <h2 class="mb-4 text-sm font-medium text-gray-500">Details</h2>
            <dl class="space-y-3 text-sm">
                <div>
                    <dt class="text-gray-500">Email</dt>
                    <dd class="text-gray-900">{{ $user->email }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Joined</dt>
                    <dd class="text-gray-900">{{ $user->created_at->format('d M Y, H:i') }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Business</dt>
                    <dd class="text-gray-900">
                        @if ($user->business)
                            {{ $user->business->name }} ({{ $user->business->plan?->name ?? 'No plan' }})
                        @else
                            None
                        @endif
                    </dd>
                </div>
            </dl>
        </div>

        <div class="rounded-lg border border-gray-200 bg-white p-6">
            <h2 class="mb-4 text-sm font-medium text-gray-500">Role</h2>
            <form method="POST" action="{{ route('admin.users.updateRole', $user) }}" class="space-y-3">
                @csrf
                @method('PATCH')

                <select name="role" class="block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-gray-500 focus:ring-gray-500">
                    <option value="{{ \App\Models\User::ROLE_BUSINESS_OWNER }}" @selected($user->role === \App\Models\User::ROLE_BUSINESS_OWNER)>Business owner</option>
                    <option value="{{ \App\Models\User::ROLE_ADMIN }}" @selected($user->role === \App\Models\User::ROLE_ADMIN)>Admin</option>
                </select>
                @error('role')
                    <p class="text-sm text-red-600">{{ $message }}</p>
                @enderror

                <button type="submit" class="w-full rounded-md bg-gray-900 px-4 py-2 text-sm text-white hover:bg-gray-700">
                    Save role
                </button>
            </form>
        </div>
    </div>
@endsection
