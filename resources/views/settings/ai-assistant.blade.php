@extends('layouts.app')

@section('title', 'AI Assistant — ' . config('app.name'))

@section('content')
    <h1 class="mb-6 text-2xl font-semibold">AI Assistant</h1>

    @if (session('status'))
        <p class="mb-4 rounded-md bg-green-50 px-4 py-3 text-sm text-green-700">{{ session('status') }}</p>
    @endif

    @if (! $eligible)
        <div class="max-w-xl rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
            <p class="text-sm text-gray-600">AI Assistant is available on eligible plans.</p>
        </div>
    @else
        <div class="max-w-xl rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
            <form method="POST" action="{{ route('ai.update') }}" class="space-y-4">
                @csrf
                @method('PUT')

                <div class="flex items-center gap-2">
                    <input id="enabled" type="checkbox" name="enabled" value="1"
                        @checked(old('enabled', $settings->enabled))
                        class="rounded border-gray-300 text-gray-900 focus:ring-gray-500">
                    <label for="enabled" class="text-sm font-medium text-gray-700">Enable AI Assistant</label>
                </div>

                <div>
                    <label for="welcome_message" class="block text-sm font-medium text-gray-700">Welcome message</label>
                    <textarea id="welcome_message" name="welcome_message" rows="2"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-gray-500 focus:ring-gray-500">{{ old('welcome_message', $settings->welcome_message) }}</textarea>
                    @error('welcome_message')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="business_instructions" class="block text-sm font-medium text-gray-700">Business instructions</label>
                    <textarea id="business_instructions" name="business_instructions" rows="5"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-gray-500 focus:ring-gray-500">{{ old('business_instructions', $settings->business_instructions) }}</textarea>
                    <p class="mt-1 text-xs text-gray-500">Preferences for how the assistant should talk about your store. They cannot override platform rules.</p>
                    @error('business_instructions')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="tone" class="block text-sm font-medium text-gray-700">Tone</label>
                    <select id="tone" name="tone"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-gray-500 focus:ring-gray-500">
                        @foreach (\App\Models\AiAssistantSetting::TONES as $tone)
                            <option value="{{ $tone }}" @selected(old('tone', $settings->tone) === $tone)>{{ ucfirst($tone) }}</option>
                        @endforeach
                    </select>
                    @error('tone')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <button type="submit" class="w-full rounded-md bg-gray-900 px-4 py-2 text-white hover:bg-gray-700">
                    Save changes
                </button>
            </form>
        </div>
    @endif
@endsection
