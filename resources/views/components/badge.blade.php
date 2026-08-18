@props([
    'type' => 'secondary',
])

<span {{ $attributes->merge(['class' => "badge text-bg-{$type}"]) }}>
    {{ $slot }}
</span>
