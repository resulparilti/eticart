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
                Shopify ID: {{ $product->shopify_product_id }}
                @if ($product->handle)
                    · {{ $product->handle }}
                @endif
                · <x-badge :type="$product->status === 'active' ? 'success' : 'secondary'">{{ $product->statusLabel() }}</x-badge>
            </p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            @if ($adminUrl)
                <a href="{{ $adminUrl }}" target="_blank" rel="noopener noreferrer" class="btn btn-outline-secondary">
                    <i class="bi bi-box-arrow-up-right me-1"></i> Shopify'da Aç
                </a>
            @endif
            <a href="{{ route('products.index', ['tab' => 'shopify']) }}" class="btn btn-outline-secondary">Geri</a>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-lg-5">
            <div class="eticart-card p-3 h-100">
                <h2 class="h6 mb-3">Galeri</h2>
                <x-product-gallery :images="$lightboxImages" :alt="$product->title" />
            </div>
        </div>
        <div class="col-lg-7">
            <div class="eticart-card p-3 h-100">
                <h2 class="h6 mb-3">Genel Bilgiler</h2>
                <dl class="row mb-0 small">
                    <dt class="col-4 eticart-muted">SKU</dt>
                    <dd class="col-8">{{ $product->sku ?: '-' }}</dd>
                    <dt class="col-4 eticart-muted">Fiyat</dt>
                    <dd class="col-8">{{ $product->priceLabel() }}</dd>
                    <dt class="col-4 eticart-muted">Toplam Stok</dt>
                    <dd class="col-8">{{ $product->stock }}</dd>
                    <dt class="col-4 eticart-muted">Varyant</dt>
                    <dd class="col-8">{{ $product->variant_count }}</dd>
                    <dt class="col-4 eticart-muted">UyumSoft</dt>
                    <dd class="col-8">
                        @if ($product->uyumSoftProduct)
                            <a href="{{ route('products.show', $product->uyumSoftProduct) }}">{{ $product->uyumSoftProduct->uyumsoft_id }}</a>
                        @else
                            Eşleşmedi
                        @endif
                    </dd>
                    <dt class="col-4 eticart-muted">Son Sync</dt>
                    <dd class="col-8">{{ optional($product->last_sync)->format('d.m.Y H:i') ?: '-' }}</dd>
                </dl>
            </div>
        </div>
    </div>

    <div class="eticart-card p-3 mb-3">
        <h2 class="h6 mb-3">Açıklama</h2>
        @if ($product->description)
            <div class="small">{!! $product->description !!}</div>
        @else
            <p class="eticart-muted mb-0">Açıklama yok.</p>
        @endif
    </div>

    <div class="eticart-card p-3">
        <h2 class="h6 mb-3">Varyantlar</h2>
        @if (empty($variants))
            <p class="eticart-muted mb-0">Varyant yok.</p>
        @else
            <div class="table-responsive">
                <x-table :headers="['Görsel', 'Başlık', 'SKU', 'Barkod', 'Fiyat', 'Stok']">
                    @foreach ($variants as $variant)
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
                                         width="48"
                                         height="48"
                                         style="width: 48px; height: 48px; object-fit: cover;">
                                </button>
                            @else
                                    <span class="small eticart-muted">—</span>
                                @endif
                            </td>
                            <td>{{ $variant['title'] ?? '-' }}</td>
                            <td>{{ $variant['sku'] ?? '-' }}</td>
                            <td>{{ $variant['barcode'] ?? '-' }}</td>
                            <td>₺{{ number_format((float) ($variant['price'] ?? 0), 2) }}</td>
                            <td>{{ $variant['stock'] ?? 0 }}</td>
                        </tr>
                    @endforeach
                </x-table>
            </div>
        @endif
    </div>

    @php
        $shopifyCollections = is_array($product->collections) ? $product->collections : [];
        $shopifyMetafields = $shopifyMetafields ?? [];
    @endphp
    @if ($shopifyMetafields !== [] || $shopifyCollections !== [])
        <div class="row g-3 mt-1">
            @if ($shopifyCollections !== [])
                <div class="col-12 col-lg-6">
                    <div class="eticart-card p-3 h-100">
                        <h2 class="h6 mb-3">Koleksiyonlar</h2>
                        <div class="d-flex flex-wrap gap-1">
                            @foreach ($shopifyCollections as $collection)
                                <span class="badge text-bg-light border">{{ $collection['title'] ?? $collection['handle'] ?? $collection['id'] }}</span>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endif
            @if ($shopifyMetafields !== [])
                <div class="col-12">
                    <x-shopify-metafields :fields="$shopifyMetafields" />
                </div>
            @endif
        </div>
    @endif

    <x-image-lightbox />
</div>
@endsection
