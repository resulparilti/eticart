@props([
    'company' => null,
    'shipment' => null,
    'height' => 24,
])

@php
    $company = $company ?? $shipment?->cargoCompany;
    $url = $company?->logoUrl() ?? $shipment?->cargoLogoUrl();
    $name = $company?->name ?? 'Kargo';
@endphp

@once
<style>
    .cargo-provider-logo { display: inline-block; max-width: 110px; height: 24px; object-fit: contain; vertical-align: middle; border-radius: 4px; }
</style>
@endonce
@if ($url)
    <span class="d-inline-flex align-items-center gap-1">
        <img src="{{ $url }}"
             alt="{{ $name }}"
             title="{{ $name }}"
             class="cargo-provider-logo"
             height="{{ $height }}"
             loading="lazy"
             onerror="this.classList.add('d-none'); this.nextElementSibling?.classList.remove('d-none');">
        <span class="d-none small">{{ $name }}</span>
    </span>
@elseif ($company)
    <span class="small">{{ $name }}</span>
@else
    <span class="eticart-muted small">—</span>
@endif
