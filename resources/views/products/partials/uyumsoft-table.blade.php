@if ($products->isEmpty())
    <x-empty-state
        title="Ürün bulunamadı"
        message="UyumSoft’tan ürün çekerek listeyi doldurun."
        icon="bi-box"
    />
@else
    <form method="POST" action="{{ route('products.bulk') }}" id="bulkProductsForm"
          data-confirm="Seçilen ürünler için işlem onaylansın mı?">
        @csrf

        <div class="eticart-card p-3 mb-3">
            <div class="fw-semibold mb-2">Toplu işlemler</div>
            <div class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label">İşlem</label>
                    <select name="action" id="bulkAction" class="form-select" required>
                        <option value="reconcile">Güncellemeleri kontrol et (UyumSoft → Shopify)</option>
                        <option value="pull_shopify">Shopify’dan eşitle (seçilenler / tüm eşitler)</option>
                        <option value="push_shopify">Seçilenleri Shopify’a aktar</option>
                        <option value="activate">Seçilenleri aktifleştir</option>
                        <option value="deactivate">Seçilenleri pasife al</option>
                        <option value="export_excel">Excel / CSV dışa aktar</option>
                    </select>
                </div>
                <div class="col-md-5" id="bulkSyncOptions">
                    <label class="form-label">Shopify aktarım seçenekleri</label>
                    <div class="d-flex flex-wrap gap-3">
                        @foreach ($syncOptions as $value => $label)
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="sync_options[]" value="{{ $value }}"
                                       id="opt_{{ $value }}" @checked($value === 'all')>
                                <label class="form-check-label" for="opt_{{ $value }}">{{ $label }}</label>
                            </div>
                        @endforeach
                    </div>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-primary w-100" id="bulkSubmitBtn">
                        Uygula
                    </button>
                </div>
            </div>
            <div class="small eticart-muted mt-2">
                Toplu işlemler kuyruğa alınır; ilerlemeyi sağ alttaki işlem izleyiciden takip edebilirsiniz.
                <strong>Güncellemeleri kontrol et</strong> seçili ürün gerektirmez; UyumSoft kataloğunu yeniler ve Shopify’da eşitlenmiş ürünleri günceller.
                <strong>Shopify’dan eşitle</strong> seçili ürünlerin (veya seçim yoksa tüm eşitlenmiş ürünlerin) görsellerini, meta alanlarını ve koleksiyonlarını Shopify’dan çeker.
                Excel dışa aktarmada seçim yoksa filtreli tüm liste indirilir (anında).
                Pasif ürünler UyumSoft’tan gelmeye devam eder; Shopify’a aktarılmaz.
            </div>
        </div>
    </form>

    @include('products.partials.list-pager', ['pagerId' => 'productPagerTop', 'class' => 'mb-3'])

    <x-table :headers="['', 'Görsel', '', 'Başlık', 'SKU', 'Barkod', 'Fiyat', 'Stok', 'Varyant', 'Durum', 'İşlem']">
            <tr class="table-light">
                <td colspan="11" class="py-2">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="selectAllProducts" form="bulkProductsForm">
                        <label class="form-check-label" for="selectAllProducts">Tümünü seç</label>
                    </div>
                </td>
            </tr>
            @foreach ($products as $product)
                @php
                    $variantCount = count($product->variantRows());
                @endphp
                <tr class="{{ $product->is_active ? '' : 'table-secondary' }}">
                    <td style="width: 40px;">
                        <input type="checkbox" name="product_ids[]" value="{{ $product->id }}" class="form-check-input product-check" form="bulkProductsForm">
                    </td>
                    <td style="width: 56px;">
                        <a href="{{ route('products.show', $product) }}" class="d-inline-block">
                            <x-product-list-thumb :url="$product->primaryImageUrl()" :alt="$product->title" />
                        </a>
                    </td>
                    <td style="width: 36px;" class="text-center">
                        @if ($product->synced_to_shopify)
                            <span
                                class="text-success"
                                title="Son Shopify eşitleme: {{ optional($product->shopify_synced_at ?? $product->last_sync)->format('d.m.Y H:i') ?: 'bilinmiyor' }}"
                                data-bs-toggle="tooltip"
                            >
                                <i class="bi bi-check-circle-fill fs-5"></i>
                            </span>
                        @else
                            <span class="text-muted" title="Shopify ile eşitlenmedi" data-bs-toggle="tooltip">
                                <i class="bi bi-circle fs-5"></i>
                            </span>
                        @endif
                    </td>
                    <td>
                        <a href="{{ route('products.show', $product) }}" class="fw-semibold text-decoration-none">
                            {{ $product->title }}
                        </a>
                        @unless ($product->is_active)
                            <x-badge type="secondary">Pasif</x-badge>
                        @endunless
                    </td>
                    <td>{{ $product->sku ?: '-' }}</td>
                    <td>{{ $product->barcode ?: '-' }}</td>
                    <td>₺{{ number_format((float) $product->original_price, 2) }}</td>
                    <td>{{ $product->stock }}</td>
                    <td>{{ $variantCount }}</td>
                    <td>
                        @if ($product->synced_to_shopify)
                            <x-badge type="success">Eşit</x-badge>
                        @else
                            <x-badge type="secondary">Bekliyor</x-badge>
                        @endif
                    </td>
                    <td class="text-nowrap">
                        <a href="{{ route('products.show', $product) }}" class="btn btn-sm btn-outline-primary">Detay</a>
                        @if (\App\Support\PermissionCatalog::allows(auth()->user(), 'products.update'))
                            <a href="{{ route('products.edit', $product) }}" class="btn btn-sm btn-outline-secondary">Düzenle</a>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-table>

    @include('products.partials.list-pager', ['pagerId' => 'productPagerBottom', 'class' => 'mt-3'])
@endif

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const selectAll = document.getElementById('selectAllProducts');
    const checks = document.querySelectorAll('.product-check');
    selectAll?.addEventListener('change', () => {
        checks.forEach((c) => { c.checked = selectAll.checked; });
    });

    const action = document.getElementById('bulkAction');
    const options = document.getElementById('bulkSyncOptions');
    const form = document.getElementById('bulkProductsForm');

    const toggleOptions = () => {
        if (!action) return;
        const value = action.value;
        if (options) {
            options.style.display = value === 'push_shopify' ? '' : 'none';
        }
        if (form) {
            if (value === 'export_excel' || value === 'reconcile' || value === 'pull_shopify') {
                form.removeAttribute('data-confirm');
            } else {
                form.setAttribute('data-confirm', 'Seçilen ürünler için işlem kuyruğa alınsın mı?');
            }
        }
    };
    action?.addEventListener('change', toggleOptions);
    toggleOptions();

    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((el) => {
        if (window.bootstrap?.Tooltip) {
            new bootstrap.Tooltip(el);
        }
    });
});
</script>
@endpush
