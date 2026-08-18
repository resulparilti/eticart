@props([
    'action' => '#',
    'method' => 'POST',
])

@php
    $spoofMethod = !in_array(strtoupper($method), ['GET', 'POST'], true);
@endphp

<form action="{{ $action }}" method="{{ $spoofMethod ? 'POST' : $method }}" {{ $attributes }}>
    @csrf
    @if ($spoofMethod)
        @method($method)
    @endif
    {{ $slot }}
</form>
