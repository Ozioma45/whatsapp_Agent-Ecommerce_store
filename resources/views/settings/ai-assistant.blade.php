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

        <div class="mt-8 max-w-xl rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
            <div class="mb-1 flex flex-wrap items-center gap-2">
                <h2 class="text-lg font-semibold">Conversation simulator</h2>
                <span class="rounded-full bg-yellow-100 px-2 py-0.5 text-xs font-medium text-yellow-800">
                    Simulation — no real WhatsApp message sent
                </span>
            </div>
            <p class="mb-4 text-sm text-gray-500">
                Try a customer message and see how the assistant would respond, using a deterministic test
                provider — not a live AI call, and no message is sent to any real WhatsApp number.
            </p>

            <form method="POST" action="{{ route('ai.simulate') }}" class="space-y-3">
                @csrf
                <div>
                    <label for="simulated_message" class="block text-sm font-medium text-gray-700">Customer message</label>
                    <textarea id="simulated_message" name="message" rows="2" required
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-gray-500 focus:ring-gray-500">{{ old('message') }}</textarea>
                    <p class="mt-1 text-xs text-gray-500">
                        Try things like "Do you have [a product name]?", "2 [product name]", "checkout", "confirm", or "cancel".
                    </p>
                    @error('message')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <button type="submit" class="w-full rounded-md bg-gray-900 px-4 py-2 text-white hover:bg-gray-700">
                    Send test message
                </button>
            </form>

            <form method="POST" action="{{ route('ai.simulate.reset') }}" class="mt-2">
                @csrf
                <button type="submit" class="w-full rounded-md border border-gray-300 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                    Reset simulated conversation
                </button>
            </form>

            @if (session('simulation'))
                @php($simulation = session('simulation'))
                <div class="mt-4 rounded-md border border-gray-200 bg-gray-50 p-4 text-sm">
                    @if ($simulation['successful'])
                        <p class="text-gray-500">You said:</p>
                        <p class="mb-2 text-gray-900">{{ $simulation['customer_message'] }}</p>
                        <p class="text-gray-500">Assistant replied:</p>
                        <p class="mb-2 whitespace-pre-line text-gray-900">{{ $simulation['reply'] }}</p>

                        @if ($simulation['simulated_order_created'] ?? false)
                            <p class="mb-2 inline-block rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-800">
                                Simulated order — no real order was created
                            </p>
                        @endif

                        @if (! empty($simulation['draft']['lines'] ?? []))
                            <p class="mt-3 text-gray-500">Current simulated draft:</p>
                            <ul class="list-disc pl-5 text-gray-900">
                                @foreach ($simulation['draft']['lines'] as $line)
                                    <li>{{ $line['quantity'] }} x {{ $line['name'] }} @ {{ $line['unit_price'] }} = {{ $line['subtotal'] }}</li>
                                @endforeach
                            </ul>
                            <p class="mt-1 text-gray-900">Draft total: {{ $simulation['draft']['total'] }}</p>
                        @endif

                        @if (! empty($simulation['products']))
                            <p class="mt-3 text-gray-500">Product information used:</p>
                            <ul class="list-disc pl-5 text-gray-900">
                                @foreach ($simulation['products'] as $product)
                                    <li>{{ $product['name'] }} — {{ $product['price'] }} ({{ $product['available'] ? 'available' : 'unavailable' }})</li>
                                @endforeach
                            </ul>
                        @endif
                    @else
                        <p class="text-red-600">
                            @switch($simulation['status'])
                                @case('not_eligible')
                                    The AI Assistant is not available on your current plan.
                                    @break
                                @case('disabled')
                                    The AI Assistant is currently disabled. Enable it above and try again.
                                    @break
                                @case('provider_unavailable')
                                    The AI provider is unavailable right now.
                                    @break
                                @case('empty_message')
                                    Please enter a message to simulate.
                                    @break
                                @default
                                    Something went wrong running the simulation.
                            @endswitch
                        </p>
                    @endif
                </div>
            @endif
        </div>
    @endif
@endsection
