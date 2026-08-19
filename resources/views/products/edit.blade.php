@extends('layouts.app')

@section('title', 'Ürün Düzenle')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">Ürün Düzenle</h1>
            <p class="eticart-muted mb-0">{{ $product->uyumsoft_id }}</p>
        </div>
        <a href="{{ route('products.show', $product) }}" class="btn btn-outline-secondary">Geri</a>
    </div>

    <div class="eticart-card p-3" style="max-width: 1080px;">
        <form method="POST" action="{{ route('products.update', $product) }}" enctype="multipart/form-data"
              data-confirm="Değişiklikler kaydedilsin mi? Shopify eşitleme seçiliyse ürün Shopify’a da gönderilecek.">
            @csrf
            @method('PUT')

            <div class="mb-3">
                <label for="title" class="form-label">Başlık</label>
                <input id="title" type="text" name="title" value="{{ old('title', $product->title) }}" class="form-control @error('title') is-invalid @enderror" required>
                @error('title') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label for="sku" class="form-label">SKU</label>
                    <input id="sku" type="text" name="sku" value="{{ old('sku', $product->sku) }}" class="form-control">
                </div>
                <div class="col-md-6">
                    <label for="barcode" class="form-label">Barkod</label>
                    <input id="barcode" type="text" name="barcode" value="{{ old('barcode', $product->barcode) }}" class="form-control">
                </div>
            </div>

            <div class="mb-3">
                <label for="description" class="form-label">Açıklama</label>
                <textarea id="description" name="description" rows="5" class="form-control">{{ old('description', $product->description) }}</textarea>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label for="original_price" class="form-label">Fiyat</label>
                    <input id="original_price" type="number" step="0.01" min="0" name="original_price" value="{{ old('original_price', $product->original_price) }}" class="form-control" required>
                </div>
                <div class="col-md-6">
                    <label for="stock" class="form-label">Stok (toplam)</label>
                    <input id="stock" type="number" min="0" name="stock" value="{{ old('stock', $product->stock) }}" class="form-control" required>
                    <div class="form-text">Varyant stokları UyumSoft depo detayından otomatik gelir; manuel toplam Shopify tek-varyant senaryolarında kullanılır.</div>
                </div>
            </div>

            <div class="mb-3">
                <label for="images_text" class="form-label">Görsel URL’leri (her satıra bir adres)</label>
                <textarea id="images_text" name="images_text" rows="4" class="form-control" placeholder="https://...">{{ old('images_text', $imagesText) }}</textarea>
            </div>

            <div class="mb-3">
                <label for="image_files" class="form-label">Yeni görsel yükle</label>
                <input id="image_files" type="file" name="image_files[]" class="form-control @error('image_files') is-invalid @enderror @error('image_files.*') is-invalid @enderror" accept="image/*" multiple>
                @error('image_files') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                @error('image_files.*') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                <div class="form-text">Ana ürün galerisi. Aşağıdan her varyanta ayrı görsel de yükleyebilirsiniz; Shopify’a ürün açmadan önce burada hazırlanır.</div>
            </div>

            @php
                $editVariants = is_array($product->variant_info['variants'] ?? null)
                    ? $product->variant_info['variants']
                    : [];
            @endphp
            @if ($editVariants !== [])
                <div class="mb-3">
                    <label class="form-label">Varyant görselleri</label>
                    <p class="form-text mt-0">Her renk/beden için ayrı görsel. Shopify’a eşitlemede ilgili varyanta bağlanır.</p>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Varyant</th>
                                    <th>Görsel</th>
                                    <th>Yeni dosya</th>
                                    <th>veya URL</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($editVariants as $index => $variant)
                                    @php
                                        $variantImage = is_array($variant) ? ($variant['image'] ?? '') : '';
                                        $variantTitle = is_array($variant) ? ($variant['title'] ?? 'Varyant') : 'Varyant';
                                    @endphp
                                    <tr>
                                        <td>
                                            <div class="fw-semibold">{{ $variantTitle }}</div>
                                            <div class="small eticart-muted">{{ $variant['sku'] ?? '' }}</div>
                                        </td>
                                        <td style="width: 72px;">
                                            @if ($variantImage)
                                                <img src="{{ $variantImage }}" alt="{{ $variantTitle }}" class="rounded border" style="width: 56px; height: 56px; object-fit: cover;">
                                            @else
                                                <span class="small eticart-muted">Yok</span>
                                            @endif
                                        </td>
                                        <td>
                                            <input type="file" name="variant_image_files[{{ $index }}]" class="form-control form-control-sm" accept="image/*">
                                        </td>
                                        <td>
                                            <input type="url" name="variant_image_urls[{{ $index }}]" class="form-control form-control-sm" placeholder="https://..." value="{{ old('variant_image_urls.'.$index) }}">
                                        </td>
                                        <td class="text-nowrap">
                                            @if ($variantImage)
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="variant_image_remove[{{ $index }}]" value="1" id="variant_remove_{{ $index }}">
                                                    <label class="form-check-label small" for="variant_remove_{{ $index }}">Kaldır</label>
                                                </div>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @error('variant_image_files.*') <div class="invalid-feedback d-block">{{ $message }} </div> @enderror
                </div>
            @endif

            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" name="is_active" value="1" id="is_active" @checked(old('is_active', $product->is_active))>
                <label class="form-check-label" for="is_active">Aktif (pasif ürünler Shopify’a aktarılmaz)</label>
            </div>

            <div class="border rounded p-3 mb-3">
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" name="push_to_shopify" value="1" id="push_to_shopify" @checked(old('push_to_shopify', true))>
                    <label class="form-check-label" for="push_to_shopify">Kaydettikten sonra Shopify ile eşitle (bilgi + görseller dahil)</label>
                </div>
                <div class="d-flex flex-wrap gap-3">
                    @foreach (['all' => 'Tümü', 'info' => 'Bilgi', 'images' => 'Görsel', 'stock' => 'Stok', 'price' => 'Fiyat'] as $val => $lab)
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="sync_options[]" value="{{ $val }}" id="edit_opt_{{ $val }}" @checked($val === 'all')>
                            <label class="form-check-label" for="edit_opt_{{ $val }}">{{ $lab }}</label>
                        </div>
                    @endforeach
                </div>
                <p class="small eticart-muted mb-0 mt-2">
                    UyumSoft Cloud API ürün kartı güncelleme endpoint’i sunmadığı için başlık/açıklama önce bu sistemde saklanır; Shopify’a buradan gönderilir.
                    Katalog alanları bir sonraki UyumSoft çekiminde ERP’den yeniden yazılabilir.
                </p>
            </div>

            <button type="submit" class="btn btn-primary">Kaydet</button>
        </form>
    </div>
@endsection
