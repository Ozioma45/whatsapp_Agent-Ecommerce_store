<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>@yield('title', 'Admin — ' . config('app.name'))</title>

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-gray-50 text-gray-900 antialiased">
        @php
            $adminNav = [
                ['route' => 'admin.dashboard', 'match' => 'admin.dashboard', 'label' => 'Dashboard'],
                ['route' => 'admin.businesses.index', 'match' => 'admin.businesses.*', 'label' => 'Businesses'],
                ['route' => 'admin.plans.index', 'match' => 'admin.plans.*', 'label' => 'Plans'],
                ['route' => 'admin.subscriptions.index', 'match' => 'admin.subscriptions.*', 'label' => 'Subscriptions'],
                ['route' => 'admin.payments.index', 'match' => 'admin.payments.*', 'label' => 'Payments'],
                ['route' => 'admin.features.index', 'match' => 'admin.features.*', 'label' => 'Features'],
                ['route' => 'admin.users.index', 'match' => 'admin.users.*', 'label' => 'Users'],
                ['route' => 'admin.settings.edit', 'match' => 'admin.settings.*', 'label' => 'Settings'],
            ];
        @endphp

        <div class="flex min-h-screen flex-col lg:flex-row">
            <aside class="border-b border-gray-200 bg-white lg:w-56 lg:shrink-0 lg:border-b-0 lg:border-r">
                <a href="{{ route('admin.dashboard') }}" class="block px-4 py-4 text-sm font-semibold text-gray-900 lg:px-6">
                    {{ config('app.name') }} <span class="text-gray-400">Admin</span>
                </a>

                <nav class="flex flex-wrap gap-1 px-4 pb-4 text-sm lg:flex-col lg:gap-0.5 lg:px-3">
                    @foreach ($adminNav as $link)
                        <a href="{{ route($link['route']) }}"
                            class="rounded-md px-3 py-2 {{ request()->routeIs($link['match']) ? 'bg-gray-900 text-white' : 'text-gray-700 hover:bg-gray-100' }}">
                            {{ $link['label'] }}
                        </a>
                    @endforeach
                </nav>
            </aside>

            <div class="min-w-0 flex-1">
                <header class="border-b border-gray-200 bg-white">
                    <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-4 sm:px-6">
                        <span class="text-sm text-gray-500">Platform admin</span>

                        <div class="flex items-center gap-4 text-sm">
                            <span class="text-gray-700">{{ auth()->user()->name }}</span>
                            <a href="{{ route('dashboard') }}" class="text-gray-500 underline hover:text-gray-700">Business dashboard</a>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" class="text-gray-500 hover:text-gray-700">Logout</button>
                            </form>
                        </div>
                    </div>
                </header>

                <main class="px-4 py-8 sm:px-6">
                    @yield('content')
                </main>
            </div>
        </div>
    </body>
</html>
