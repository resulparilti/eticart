@extends('layouts.app')

@section('title', $product->title)

@section('content')
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
                @if (empty($images))
                    <p class="eticart-muted mb-0">Görsel yok. Düzenle’den URL ekleyebilirsiniz.</p>
                @else
                    <div class="row g-2">
                        @foreach ($images as $url)
                            <div class="col-6">
                                <img src="{{ $url }}" alt="" class="img-fluid rounded border" loading="lazy" style="max-height:140px;object-fit:cover;width:100%;">
                            </div>
                        @endforeach
                    </div>
                @endif
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
            <x-table :headers="['Varyant', 'SKU', 'Barkod', 'Fiyat', 'Stok']">
                @foreach ($variants as $variant)
                    <tr>
                        <td>{{ $variant['title'] ?? '-' }}</td>
                        <td>{{ $variant['sku'] ?? '-' }}</td>
                        <td>{{ $variant['barcode'] ?? '-' }}</td>
                        <td>₺{{ number_format((float) ($variant['price'] ?? 0), 2) }}</td>
                        <td>{{ $variant['stock'] ?? '-' }}</td>
                    </tr>
                @endforeach
            </x-table>
        </div>
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
