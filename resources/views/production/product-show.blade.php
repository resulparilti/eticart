@extends('layouts.app')

@section('title', $product->title)

@section('content')
@php
    $lightboxImages = array_values(array_unique(array_filter(array_map(
        'strval',
        array_merge($images ?? [], array_column($variants ?? [], 'image') ?: [])
    ))));
@endphp
<div x-data="productMedia({{ \Illuminate\Support\Js::from($lightboxImages) }})">
    <div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
        <div>
            <h1 class="h3 mb-1">{{ $product->title }}</h1>
            <p class="eticart-muted mb-0">
                SKU: {{ $product->sku ?: '-' }}
                · Barkod: {{ $product->barcode ?: '-' }}
                · Stok: {{ $product->stock }}
            </p>
        </div>
        <a href="{{ route('production.products.index') }}" class="btn btn-outline-secondary">Listeye dön</a>
    </div>

    <div class="row g-3">
        <div class="col-12 col-lg-4">
            <div class="eticart-card p-3 h-100">
                <h2 class="h6 mb-3">Görseller</h2>
                <x-product-gallery :images="$lightboxImages" :alt="$product->title" empty="Görsel yok." />
            </div>
        </div>
        <div class="col-12 col-lg-8">
            <div class="eticart-card p-3 h-100">
                <h2 class="h6 mb-3">Varyant stokları ({{ count($variants) }})</h2>
                <div class="table-responsive">
                    <x-table :headers="['Görsel', 'Varyant', 'SKU', 'Barkod', 'Stok']">
                        @foreach ($variants as $variant)
                            @php $stock = is_numeric($variant['stock'] ?? null) ? (int) $variant['stock'] : 0; @endphp
                            <tr>
                                <td style="width: 64px;">
                                    @if (! empty($variant['image']))
                                        <button type="button"
                                                class="eticart-gallery__thumb p-0 border-0 bg-transparent"
                                                data-full-url="{{ $variant['image'] }}"
                                                @click="openLightbox($el.dataset.fullUrl)"
                                                title="Büyüt">
                                            <img src="{{ \App\Support\ShopifyMetafieldFormatter::cdnWidth($variant['image'], 96) }}"
                                                 alt="{{ $variant['title'] ?? 'Varyant' }}"
                                                 class="rounded border"
                                                 loading="lazy"
                                                 decoding="async"
                                                 width="48"
                                                 height="48"
                                                 style="width: 48px; height: 48px; object-fit: cover;">
                                        </button>
                                    @else
                                        <span class="small eticart-muted">—</span>
                                    @endif
                                </td>
                                <td>{{ $variant['title'] ?? 'Varyant' }}</td>
                                <td>{{ $variant['sku'] ?? '-' }}</td>
                                <td class="font-monospace">{{ $variant['barcode'] ?? '-' }}</td>
                                <td class="fw-semibold {{ $stock <= 0 ? 'text-danger' : ($stock <= 5 ? 'text-warning' : '') }}">
                                    {{ $variant['stock'] ?? '-' }}
                                </td>
                            </tr>
                        @endforeach
                    </x-table>
                </div>
            </div>
        </div>
    </div>

    <x-image-lightbox />
</div>
@endsection
