@extends('layouts.app')

@section('title', $product->title)

@section('content')
@php
    $lightboxImages = array_values(array_unique(array_filter(array_map(
        'strval',
        array_merge($images ?? [], array_column($variants ?? [], 'image') ?: [])
    ))));
@endphp
<div x-data='productMedia(@js($lightboxImages))'>
    <div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
        <div>
            <h1 class="h3 mb-1">
                @if ($product->synced_to_shopify)
                    <span class="text-success me-1" title="Son Shopify: {{ optional($product->shopify_synced_at)->format('d.m.Y H:i') }}" data-bs-toggle="tooltip">
                        <i class="bi bi-check-circle-fill"></i>
                    </span>
                @endif
                {{ $product->title }}
            </h1>
            <p class="eticart-muted mb-0">
                UyumSoft ID: {{ $product->uyumsoft_id }}
                @unless ($product->is_active)
                    · <x-badge type="secondary">Pasif</x-badge>
                @endunless
            </p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <form method="POST" action="{{ route('products.toggle-active', $product) }}">
                @csrf
                <button class="btn btn-outline-secondary" type="submit"
                        data-confirm="{{ $product->is_active ? 'Ürün pasife alınsın mı? Shopify’a aktarılmayacak.' : 'Ürün aktifleştirilsin mi?' }}">
                    {{ $product->is_active ? 'Pasife Al' : 'Aktifleştir' }}
                </button>
            </form>
            <a href="{{ route('products.edit', $product) }}" class="btn btn-outline-primary">Düzenle</a>
            <a href="{{ route('products.index') }}" class="btn btn-outline-secondary">Geri</a>
        </div>
    </div>

    @if (session('sync_results'))
        <div class="eticart-card p-3 mb-3">
            <h2 class="h6 mb-2">Eşitleme Sonucu</h2>
            @foreach (session('sync_results') as $result)
                <div class="d-flex justify-content-between border-bottom py-2">
                    <span>{{ $result['title'] ?? $product->title }}</span>
                    <span>
                        <x-badge :type="($result['status'] ?? '') === 'success' ? 'success' : (($result['status'] ?? '') === 'skipped' ? 'warning' : 'danger')">
                            {{ $result['status'] ?? '-' }}
                        </x-badge>
                        <span class="small eticart-muted ms-2">{{ $result['shopify_product_id'] ?? ($result['message'] ?? '') }}</span>
                    </span>
                </div>
            @endforeach
        </div>
    @endif

    <div class="row g-3 mb-3">
        <div class="col-lg-4">
            <div class="eticart-card p-3 h-100">
                <h2 class="h6 mb-3">Görseller</h2>
                <x-product-gallery :images="$images" :alt="$product->title" empty="Görsel yok. Düzenle’den URL ekleyebilirsiniz." />
            </div>
        </div>
        <div class="col-lg-4">
            <div class="eticart-card p-3 h-100">
                <h2 class="h6 mb-3">Genel Bilgiler</h2>
                <dl class="row mb-0 small">
                    <dt class="col-4 eticart-muted">SKU</dt>
                    <dd class="col-8">{{ $product->sku ?: '-' }}</dd>
                    <dt class="col-4 eticart-muted">Barkod</dt>
                    <dd class="col-8">{{ $product->barcode ?: '-' }}</dd>
                    <dt class="col-4 eticart-muted">Fiyat</dt>
                    <dd class="col-8">₺{{ number_format((float) $product->original_price, 2) }}</dd>
                    <dt class="col-4 eticart-muted">Stok</dt>
                    <dd class="col-8">{{ $product->stock }}</dd>
                    <dt class="col-4 eticart-muted">UyumSoft Sync</dt>
                    <dd class="col-8">{{ optional($product->last_sync)->format('d.m.Y H:i') ?: '-' }}</dd>
                    <dt class="col-4 eticart-muted">Shopify Sync</dt>
                    <dd class="col-8">{{ optional($product->shopify_synced_at)->format('d.m.Y H:i') ?: '-' }}</dd>
                </dl>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="eticart-card p-3 h-100">
                <h2 class="h6 mb-3">Shopify</h2>
                @if ($product->shopifyProduct)
                    <dl class="row mb-3 small">
                        <dt class="col-5 eticart-muted">Product ID</dt>
                        <dd class="col-7">{{ $product->shopifyProduct->shopify_product_id }}</dd>
                        <dt class="col-5 eticart-muted">Variant ID</dt>
                        <dd class="col-7">{{ $product->shopifyProduct->shopify_variant_id ?: '-' }}</dd>
                        <dt class="col-5 eticart-muted">Inventory Item</dt>
                        <dd class="col-7">{{ $product->shopifyProduct->inventory_item_id ?: '-' }}</dd>
                    </dl>
                @else
                    <p class="eticart-muted small">Henüz Shopify’a aktarılmamış.</p>
                @endif

                <form method="POST" action="{{ route('products.push-shopify', $product) }}" data-confirm="Bu ürün Shopify ile eşitlensin mi?">
                    @csrf
                    <label class="form-label small">Aktarım seçenekleri</label>
                    <div class="mb-2">
                        @foreach (['all' => 'Tümü', 'info' => 'Bilgi', 'images' => 'Görsel', 'stock' => 'Stok', 'price' => 'Fiyat'] as $val => $lab)
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="checkbox" name="sync_options[]" value="{{ $val }}" id="push_{{ $val }}" @checked($val === 'all')>
                                <label class="form-check-label small" for="push_{{ $val }}">{{ $lab }}</label>
                            </div>
                        @endforeach
                    </div>
                    <button type="submit" class="btn btn-sm btn-primary" @disabled(! $shopifyConfigured || ! $product->is_active)>
                        Shopify’a Eşitle
                    </button>
                </form>
                <form method="POST" action="{{ route('products.pull-shopify-one', $product) }}" class="mt-2"
                      data-confirm="Shopify’daki görseller, meta alanlar ve koleksiyonlar bu ürüne çekilsin mi?">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-outline-primary"
                            @disabled(! $shopifyConfigured || (blank($product->shopify_id) && blank($product->shopifyProduct?->shopify_product_id)))>
                        Shopify’dan eşitle
                    </button>
                </form>
                <div class="form-text mt-2">
                    Shopify’dan eşitle, mağazada yüklenen ürün/varyant görsellerini, meta alanları ve koleksiyon tercihlerini panele alır.
                </div>
            </div>
        </div>
    </div>

    <div class="eticart-card p-3 mb-3">
        <h2 class="h6 mb-3">Açıklama</h2>
        @if ($product->description)
            <div class="small" style="white-space: pre-wrap;">{{ $product->description }}</div>
        @else
            <p class="eticart-muted mb-0">Açıklama yok.</p>
        @endif
    </div>

    @if (! empty($attributeGroups))
        <div class="eticart-card p-3 mb-3">
            <h2 class="h6 mb-3">Özellikler</h2>
            <div class="row g-3">
                @foreach ($attributeGroups as $group)
                    <div class="col-md-6">
                        <div class="small eticart-muted mb-1">{{ $group['name'] }}</div>
                        <div class="d-flex flex-wrap gap-1">
                            @foreach ($group['values'] as $value)
                                <span class="badge text-bg-light border">{{ $value }}</span>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <div class="eticart-card p-3">
        <h2 class="h6 mb-3">Varyantlar ({{ count($variants) }})</h2>
        <div class="table-responsive">
            <x-table :headers="['Görsel', 'Varyant', 'SKU', 'Barkod', 'Fiyat', 'Stok']">
                @foreach ($variants as $variant)
                    <tr>
                        <td style="width: 64px;">
                            @if (! empty($variant['image']))
                                <button type="button"
                                        class="eticart-gallery__thumb p-0 border-0 bg-transparent"
                                        data-full-url="{{ $variant['image'] }}"
                                        @click="openLightbox($event.currentTarget.getAttribute('data-full-url'))"
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
                        <td>{{ $variant['title'] ?? '-' }}</td>
                        <td>{{ $variant['sku'] ?? '-' }}</td>
                        <td>{{ $variant['barcode'] ?? '-' }}</td>
                        <td>
                            @if (! empty($variant['compare_at_price']) && (float) $variant['compare_at_price'] > (float) ($variant['price'] ?? 0))
                                <span class="text-decoration-line-through eticart-muted me-1">₺{{ number_format((float) $variant['compare_at_price'], 2) }}</span>
                            @endif
                            ₺{{ number_format((float) ($variant['price'] ?? 0), 2) }}
                        </td>
                        <td>{{ $variant['stock'] ?? '-' }}</td>
                    </tr>
                @endforeach
            </x-table>
        </div>
    </div>

    @php
        $shopifyCollections = is_array($product->shopifyProduct?->collections) ? $product->shopifyProduct->collections : [];
        $shopifyMetafields = $shopifyMetafields ?? [];
    @endphp
    @if ($shopifyMetafields !== [] || $shopifyCollections !== [])
        <div class="row g-3 mt-1">
            @if ($shopifyCollections !== [])
                <div class="col-12 col-lg-6">
                    <div class="eticart-card p-3 h-100">
                        <h2 class="h6 mb-3">Shopify koleksiyonları</h2>
                        <div class="d-flex flex-wrap gap-1">
                            @foreach ($shopifyCollections as $collection)
                                <span class="badge text-bg-light border">
                                    {{ $collection['title'] ?? $collection['handle'] ?? $collection['id'] ?? 'Koleksiyon' }}
                                    @if (($collection['kind'] ?? '') === 'smart')
                                        <span class="eticart-muted">· otomatik</span>
                                    @endif
                                </span>
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

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((el) => {
        if (window.bootstrap?.Tooltip) new bootstrap.Tooltip(el);
    });
});
</script>
@endpush
