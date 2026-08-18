@extends('layouts.app')

@section('title', 'Shopify Sync')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h1 class="h3 mb-1">Shopify'a Aktar</h1>
            <p class="eticart-muted mb-0">Seçilen UyumSoft ürünlerini Shopify'a gönderin.</p>
        </div>
        <a href="{{ route('products.index') }}" class="btn btn-outline-secondary">Geri</a>
    </div>

    @if (! $shopifyConfigured)
        <div class="alert alert-warning">Shopify API ayarları eksik. Sync yapılamaz.</div>
    @endif

    <form method="POST" action="{{ route('products.sync-to-shopify') }}" data-confirm="Seçilen ürünler Shopify’a aktarılsın mı?">
        @csrf

        @if (collect($products)->isEmpty())
            <x-empty-state
                title="Aktarılacak ürün yok"
                message="Önce UyumSoft ürünlerini çekin, ardından listeden seçerek aktarın."
                icon="bi-box-arrow-up"
            />
        @else
            <div class="eticart-card p-3 mb-3">
                <label class="form-label">Aktarım seçenekleri</label>
                <div class="d-flex flex-wrap gap-3 mb-3">
                    @foreach ($syncOptions as $value => $label)
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="sync_options[]" value="{{ $value }}" id="sync_opt_{{ $value }}" @checked($value === 'all')>
                            <label class="form-check-label" for="sync_opt_{{ $value }}">{{ $label }}</label>
                        </div>
                    @endforeach
                </div>

                <h2 class="h5 mb-3">Önizleme</h2>
                <x-table :headers="['', 'Başlık', 'SKU', 'Fiyat', 'Stok', 'Durum']">
                    @foreach ($products as $product)
                        <tr>
                            <td>
                                <input type="checkbox" class="form-check-input" name="product_ids[]" value="{{ $product['id'] }}"
                                       @checked(empty($product['is_active']) ? false : true)
                                       @disabled(isset($product['is_active']) && ! $product['is_active'])>
                            </td>
                            <td>
                                {{ $product['title'] }}
                                @if (isset($product['is_active']) && ! $product['is_active'])
                                    <x-badge type="secondary">Pasif</x-badge>
                                @endif
                            </td>
                            <td>{{ $product['sku'] ?: '-' }}</td>
                            <td>₺{{ number_format((float) $product['price'], 2) }}</td>
                            <td>{{ $product['stock'] }}</td>
                            <td>
                                @if (!empty($product['already_synced']))
                                    <x-badge type="info">Güncellenecek</x-badge>
                                @else
                                    <x-badge type="secondary">Yeni</x-badge>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </x-table>
            </div>

            <button type="submit" class="btn btn-primary" @disabled(! $shopifyConfigured)>
                Seçilenleri Shopify'a Gönder
            </button>
        @endif
    </form>

    @if (!empty($syncResults))
        <div class="eticart-card p-3 mt-4">
            <h2 class="h5 mb-3">Sonuçlar</h2>
            <x-table :headers="['Ürün', 'Durum', 'Detay']">
                @foreach ($syncResults as $result)
                    <tr>
                        <td>{{ $result['title'] ?? ('#'.$result['id']) }}</td>
                        <td>
                            <x-badge type="{{ ($result['status'] ?? '') === 'success' ? 'success' : (($result['status'] ?? '') === 'skipped' ? 'warning' : 'danger') }}">
                                {{ $result['status'] ?? '-' }}
                            </x-badge>
                        </td>
                        <td>{{ $result['shopify_product_id'] ?? ($result['message'] ?? '-') }}</td>
                    </tr>
                @endforeach
            </x-table>
        </div>
    @endif
@endsection
