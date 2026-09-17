@php
    $statusColors = [
        'pending' => 'bg-amber-50 text-amber-700',
        'confirmed' => 'bg-blue-50 text-blue-700',
        'processing' => 'bg-purple-50 text-purple-700',
        'completed' => 'bg-green-50 text-green-700',
        'cancelled' => 'bg-gray-100 text-gray-600',
    ];
@endphp

<span class="rounded-full px-2 py-0.5 text-xs {{ $statusColors[$status] ?? 'bg-gray-100 text-gray-600' }}">
    {{ ucfirst($status) }}
</span>
