<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ \App\Models\Setting::get(\App\Models\Setting::PLATFORM_NAME, config('app.name')) }}</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="flex min-h-screen items-center justify-center bg-gray-50 px-4 text-gray-900 antialiased">
        <div class="max-w-sm text-center">
            <h1 class="text-xl font-semibold">We'll be back shortly</h1>
            <p class="mt-2 text-sm text-gray-600">
                {{ \App\Models\Setting::get(\App\Models\Setting::PLATFORM_NAME, config('app.name')) }} is undergoing
                scheduled maintenance. Please check back soon.
            </p>
        </div>
    </body>
</html>
