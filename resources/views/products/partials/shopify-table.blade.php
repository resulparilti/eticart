<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div>
        <p class="mb-0 eticart-muted small">
            Ana ürünler tek satırda listelenir; detay sayfasında galeri, açıklama ve varyantlar görünür.
        </p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        @if (! $shopifyConfigured)
            <span class="badge text-bg-warning align-self-center">Shopify ayarları eksik</span>
        @endif
        <form method="POST" action="{{ route('products.pull-shopify') }}">
            @csrf
            <button type="submit" class="btn btn-primary" @disabled(! $shopifyConfigured)>
                <i class="bi bi-cloud-download me-1"></i> Shopify'dan Çek
            </button>
        </form>
    </div>
</div>

@if ($products->isEmpty())
    <x-empty-state
        title="Shopify ürünü yok"
        message="Mağazanızdaki ürünleri listelemek için «Shopify'dan Çek» butonuna tıklayın."
        icon="bi-shop"
    />
@else
    <x-table :headers="['Başlık', 'Varyant', 'Fiyat', 'Toplam Stok', 'Shopify ID', 'UyumSoft', 'Son Sync', '']">
        @foreach ($products as $product)
            <tr>
                <td class="fw-semibold">
                    <a href="{{ route('products.shopify-mirror.show', $product) }}" class="text-decoration-none">
                        {{ $product->title }}
                    </a>
                    @if ($product->status && $product->status !== 'active')
                        <span class="badge text-bg-secondary ms-1">{{ $product->statusLabel() }}</span>
                    @endif
                </td>
                <td>
                    <span class="badge text-bg-light border">{{ $product->variant_count }} varyant</span>
                </td>
                <td>{{ $product->priceLabel() }}</td>
                <td>{{ $product->stock }}</td>
                <td><code class="small">{{ $product->shopify_product_id }}</code></td>
                <td>
                    @if ($product->uyumSoftProduct)
                        <a href="{{ route('products.show', $product->uyumSoftProduct) }}">{{ $product->uyumSoftProduct->uyumsoft_id }}</a>
                    @else
                        <span class="badge text-bg-secondary">Eşleşmedi</span>
                    @endif
                </td>
                <td>{{ optional($product->last_sync)->format('d.m.Y H:i') ?: '-' }}</td>
                <td class="text-end text-nowrap">
                    <a href="{{ route('products.shopify-mirror.show', $product) }}" class="btn btn-sm btn-outline-primary" title="Detay">
                        <i class="bi bi-eye me-1"></i> Detay
                    </a>
                    @if ($adminProductUrl = $shopifyService->adminProductUrl($product->shopify_product_id))
                        <a href="{{ $adminProductUrl }}" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-secondary" title="Shopify'da aç">
                            <i class="bi bi-box-arrow-up-right"></i>
                        </a>
                    @endif
                </td>
            </tr>
        @endforeach
    </x-table>

    <div class="mt-3">
        {{ $products->links() }}
    </div>
@endif
