@props([
    'value' => null,
    'group' => 'fulfillment',
])

@php
    $label = match ($group) {
        'payment' => \App\Support\StatusLabels::payment($value),
        'shipment' => \App\Support\StatusLabels::shipment($value),
        default => \App\Support\StatusLabels::fulfillment($value),
    };
    $type = match ($group) {
        'payment' => \App\Support\StatusLabels::paymentBadge($value),
        'shipment' => \App\Support\StatusLabels::shipmentBadge($value),
        default => \App\Support\StatusLabels::fulfillmentBadge($value),
    };
@endphp

<x-badge :type="$type" {{ $attributes }}>{{ $label }}</x-badge>
