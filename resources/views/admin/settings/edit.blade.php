@extends('layouts.admin')

@section('title', 'Settings — ' . config('app.name'))

@section('content')
    <h1 class="mb-6 text-2xl font-semibold">Platform settings</h1>

    @if (session('status'))
        <p class="mb-4 rounded-md bg-green-50 px-4 py-3 text-sm text-green-700">{{ session('status') }}</p>
    @endif

    <div class="max-w-xl rounded-lg border border-gray-200 bg-white p-6">
        <form method="POST" action="{{ route('admin.settings.update') }}" class="space-y-4">
            @csrf
            @method('PATCH')

            <div>
                <label for="platform_name" class="block text-sm font-medium text-gray-700">Platform name</label>
                <input id="platform_name" type="text" name="platform_name"
                    value="{{ old('platform_name', $settings[\App\Models\Setting::PLATFORM_NAME]) }}" required
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-gray-500 focus:ring-gray-500">
                @error('platform_name')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="platform_description" class="block text-sm font-medium text-gray-700">Platform description</label>
                <textarea id="platform_description" name="platform_description" rows="2"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-gray-500 focus:ring-gray-500">{{ old('platform_description', $settings[\App\Models\Setting::PLATFORM_DESCRIPTION]) }}</textarea>
            </div>

            <div>
                <label for="support_email" class="block text-sm font-medium text-gray-700">Support email</label>
                <input id="support_email" type="email" name="support_email"
                    value="{{ old('support_email', $settings[\App\Models\Setting::SUPPORT_EMAIL]) }}"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-gray-500 focus:ring-gray-500">
                @error('support_email')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="support_whatsapp" class="block text-sm font-medium text-gray-700">Support WhatsApp</label>
                <input id="support_whatsapp" type="text" name="support_whatsapp"
                    value="{{ old('support_whatsapp', $settings[\App\Models\Setting::SUPPORT_WHATSAPP]) }}"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-gray-500 focus:ring-gray-500">
            </div>

            <div>
                <label for="default_plan" class="block text-sm font-medium text-gray-700">Default plan for new businesses</label>
                <select id="default_plan" name="default_plan"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-gray-500 focus:ring-gray-500">
                    @foreach ($plans as $plan)
                        <option value="{{ $plan->slug }}" @selected(old('default_plan', $settings[\App\Models\Setting::DEFAULT_PLAN]) === $plan->slug)>
                            {{ $plan->name }}
                        </option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-gray-500">Only affects new registrations — existing businesses keep their current plan.</p>
                @error('default_plan')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <label class="flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" name="maintenance_mode" value="1" class="rounded border-gray-300"
                    @checked(old('maintenance_mode', $settings[\App\Models\Setting::MAINTENANCE_MODE]) == '1')>
                Maintenance mode (shows a maintenance page to public visitors — the admin area stays accessible)
            </label>

            <button type="submit" class="w-full rounded-md bg-gray-900 px-4 py-2 text-sm text-white hover:bg-gray-700">
                Save settings
            </button>
        </form>
    </div>
@endsection
