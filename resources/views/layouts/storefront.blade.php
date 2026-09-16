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
            <div class="mx-auto flex max-w-6xl items-center justify-between gap-3 px-4 py-4 sm:px-6">
                <div class="flex items-center gap-3">
                    @if ($business->setting?->logo)
                        <img src="{{ \Illuminate\Support\Facades\Storage::url($business->setting->logo) }}" alt="{{ $business->name }} logo"
                            class="h-8 w-8 rounded-full object-cover">
                    @endif

                    <span class="text-lg font-semibold">{{ $business->name }}</span>
                </div>

                <a href="{{ route('cart.index', ['business' => $business->handle]) }}"
                    class="rounded-md border border-gray-300 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">
                    Cart ({{ $cartCount ?? 0 }})
                </a>
            </div>
        </header>

        <main class="mx-auto w-full max-w-6xl flex-1 px-4 py-10 sm:px-6">
            @yield('content')
        </main>

        <footer class="border-t border-gray-200 bg-white">
            <div class="mx-auto max-w-6xl px-4 py-6 text-center text-sm text-gray-500 sm:px-6">
                {{ $business->name }} &middot; Powered by {{ config('app.name') }}
            </div>
        </footer>
    </body>
</html>
