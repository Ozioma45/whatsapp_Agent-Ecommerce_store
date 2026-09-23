<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>@yield('title', config('app.name'))</title>

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-gray-50 text-gray-900 antialiased flex flex-col">
        <header class="border-b border-gray-200 bg-white">
            <div class="mx-auto flex max-w-6xl items-center justify-between px-4 py-4 sm:px-6">
                <a href="{{ url('/') }}" class="text-lg font-semibold">
                    {{ config('app.name') }}
                </a>

                <nav class="flex items-center gap-4 text-sm">
                    @auth
                        <a href="{{ route('dashboard') }}" class="text-gray-700 hover:text-gray-900">Dashboard</a>
                        <a href="{{ route('orders.index') }}" class="text-gray-700 hover:text-gray-900">Orders</a>
                        <a href="{{ route('products.index') }}" class="text-gray-700 hover:text-gray-900">Products</a>
                        <a href="{{ route('categories.index') }}" class="text-gray-700 hover:text-gray-900">Categories</a>
                        <a href="{{ route('settings.edit') }}" class="text-gray-700 hover:text-gray-900">Store Settings</a>
                        @if (auth()->user()->isAdmin())
                            <a href="{{ route('admin.dashboard') }}" class="text-gray-700 hover:text-gray-900">Admin</a>
                        @endif
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="text-gray-700 hover:text-gray-900">Logout</button>
                        </form>
                    @else
                        <a href="{{ route('login') }}" class="text-gray-700 hover:text-gray-900">Log in</a>
                        <a href="{{ route('register') }}" class="rounded-md bg-gray-900 px-3 py-1.5 text-white hover:bg-gray-700">Register</a>
                    @endauth
                </nav>
            </div>
        </header>

        <main class="mx-auto w-full max-w-6xl flex-1 px-4 py-10 sm:px-6">
            @yield('content')
        </main>

        <footer class="border-t border-gray-200 bg-white">
            <div class="mx-auto max-w-6xl px-4 py-6 text-center text-sm text-gray-500 sm:px-6">
                &copy; {{ date('Y') }} {{ config('app.name') }}
            </div>
        </footer>
    </body>
</html>
