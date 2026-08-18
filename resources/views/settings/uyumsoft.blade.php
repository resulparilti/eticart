@extends('layouts.app')

@section('title', 'UyumSoft Ayarları')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">UyumSoft Ayarları</h1>
            <p class="eticart-muted mb-0">UyumCloud (EKO) web servis ve sipariş eşleme ayarları.</p>
        </div>
        <a href="{{ route('settings.index') }}" class="btn btn-outline-secondary">Geri</a>
    </div>

    <div class="eticart-card p-3 mb-3" style="max-width: 920px;">
        <div class="alert alert-info small mb-0">
            Entegra ile aynı bilgileri kullanıyorsanız <strong>Base URL</strong> olarak firmanıza özel
            <code>https://api-....eko.uyumcloud.com</code> adresini girin.
            Kullanıcı tipi <strong>WEB Servis</strong> olmalıdır (<code>WEBSERVIS</code>).
        </div>
    </div>

    <form method="POST" action="{{ route('settings.uyumsoft.update') }}">
        @csrf
        @method('PUT')

        <div class="eticart-card p-3 mb-3" style="max-width: 920px;">
            <h2 class="h5 mb-3">Bağlantı</h2>
            <div class="mb-3">
                <label class="form-label">API Username</label>
                <input type="text" name="uyumsoft_api_user" class="form-control" value="{{ old('uyumsoft_api_user', $settings['uyumsoft_api_user'] ?? '') }}" placeholder="WEBSERVIS">
            </div>
            <div class="mb-3">
                <label class="form-label">API Password</label>
                <input type="password" name="uyumsoft_api_password" class="form-control" value="{{ old('uyumsoft_api_password', $settings['uyumsoft_api_password'] ?? '') }}" autocomplete="new-password">
            </div>
            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label">İşyeri Kodu</label>
                    <input type="text" name="uyumsoft_branch_code" class="form-control" value="{{ old('uyumsoft_branch_code', $settings['uyumsoft_branch_code'] ?? '') }}" placeholder="Örn. 001">
                    <div class="form-text">GetItemList için zorunlu.</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Depo Kodu</label>
                    <input type="text" name="uyumsoft_warehouse_id" class="form-control" value="{{ old('uyumsoft_warehouse_id', $settings['uyumsoft_warehouse_id'] ?? '') }}" placeholder="Örn. A001">
                </div>
            </div>
            <div class="mb-0">
                <label class="form-label">Base URL</label>
                <input type="url" name="uyumsoft_base_url" class="form-control" value="{{ old('uyumsoft_base_url', $settings['uyumsoft_base_url'] ?? '') }}" placeholder="https://api-firma.eko.uyumcloud.com">
            </div>
        </div>

        <div class="eticart-card p-3 mb-3" style="max-width: 920px;">
            <h2 class="h5 mb-1">Cari (Entegra → UyumSoft → Cari sekmesi)</h2>
            <p class="eticart-muted small mb-3">
                Entegra’da <strong>Shopify.orenne</strong> için tanımlı cari bilgilerinin karşılığı.
                Torba cari kullanılmıyorsa tüm siparişler tek e-ticaret carisine yazılır.
            </p>

            <div class="mb-3">
                <label class="form-label">E-ticaret cari kodu <span class="text-danger">*</span></label>
                <input type="text" name="uyumsoft_ecommerce_entity_code" class="form-control" value="{{ old('uyumsoft_ecommerce_entity_code', $settings['uyumsoft_ecommerce_entity_code'] ?? '') }}" placeholder="Örn. 320 20 S01">
                <div class="form-text">
                    UyumSoft <strong>Cari Kart</strong> kodu (API’deki <code>entityCode</code>).
                    Muhasebe hesap kodu (<code>120 10 A001</code> / <code>120 10 501</code>) burada çalışmaz.
                    Mevcut satış siparişlerinde kullanılan cariye bakın; Shopify ile ilgili örnek:
                    <code>320 20 S01</code> (SHOPIFY INTERNATIONAL LIMITED).
                    Boş bırakılırsa sipariş gönderilmez.
                </div>
            </div>

            <div class="row g-3 mb-0">
                <div class="col-md-6">
                    <label class="form-label">Koşullu cari ön ek (Entegra: S01)</label>
                    <input type="text" name="uyumsoft_entity_prefix" class="form-control" value="{{ old('uyumsoft_entity_prefix', $settings['uyumsoft_entity_prefix'] ?? '') }}" placeholder="Örn. S01">
                    <div class="form-text">Tek e-ticaret carisi kullanıyorsanız yalnızca üstteki kod yeterlidir.</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Firma kodu</label>
                    <input type="text" name="uyumsoft_co_code" class="form-control" value="{{ old('uyumsoft_co_code', $settings['uyumsoft_co_code'] ?? '') }}" placeholder="Örn. A001">
                    <div class="form-text">Boşsa işyeri kodu kullanılır. API “Firma kodu gönderilmelidir” derse burayı doldurun.</div>
                </div>
            </div>
        </div>

        <div class="eticart-card p-3 mb-3" style="max-width: 920px;">
            <h2 class="h5 mb-1">Sipariş (Entegra → UyumSoft → Sipariş sekmesi)</h2>
            <p class="eticart-muted small mb-3">
                Entegra’daki hareket kodu ve sipariş satırı ürün adı seçeneklerinin karşılığı.
            </p>

            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label">Hareket kodu</label>
                    <input type="text" name="uyumsoft_doc_tra_code" class="form-control" value="{{ old('uyumsoft_doc_tra_code', $settings['uyumsoft_doc_tra_code'] ?? 'ST-101') }}" placeholder="ST-101">
                    <div class="form-text">Entegra’da <strong>Hareket Kodu</strong> (ör. <code>ST-101</code>).</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Birim kodu</label>
                    <input type="text" name="uyumsoft_unit_code" class="form-control" value="{{ old('uyumsoft_unit_code', $settings['uyumsoft_unit_code'] ?? 'ADET') }}" placeholder="ADET">
                    <div class="form-text">Sipariş satırı birimi. UyumSoft’ta tanımlı kod olmalı (genelde <code>ADET</code>).</div>
                </div>
            </div>

            <div class="form-check mb-2">
                <input type="hidden" name="uyumsoft_order_line_include_title" value="0">
                <input class="form-check-input" type="checkbox" name="uyumsoft_order_line_include_title" value="1" id="uyumLineTitle"
                    @checked(old('uyumsoft_order_line_include_title', $settings['uyumsoft_order_line_include_title'] ?? '1') === '1')>
                <label class="form-check-label" for="uyumLineTitle">Satırda ürün adı gönder (<code>itemName</code>)</label>
            </div>
            <div class="form-check mb-2">
                <input type="hidden" name="uyumsoft_order_line_include_variant" value="0">
                <input class="form-check-input" type="checkbox" name="uyumsoft_order_line_include_variant" value="1" id="uyumLineVariant"
                    @checked(old('uyumsoft_order_line_include_variant', $settings['uyumsoft_order_line_include_variant'] ?? '1') === '1')>
                <label class="form-check-label" for="uyumLineVariant">Ürün adının sonuna varyant ekle (Entegra: Ürün Adı + varyant)</label>
            </div>
            <div class="form-check mb-0">
                <input type="hidden" name="uyumsoft_order_line_include_barcode" value="0">
                <input class="form-check-input" type="checkbox" name="uyumsoft_order_line_include_barcode" value="1" id="uyumLineBarcode"
                    @checked(old('uyumsoft_order_line_include_barcode', $settings['uyumsoft_order_line_include_barcode'] ?? '1') === '1')>
                <label class="form-check-label" for="uyumLineBarcode">Satırda barkod gönder (<code>barCode</code> + not)</label>
                <div class="form-text">Shopify siparişindeki barkod veya UyumSoft eşleşmesinden alınır; depoda hangi varyantın gideceğini netleştirir.</div>
            </div>
        </div>

        <div class="mb-3" style="max-width: 920px;">
            <button type="submit" class="btn btn-primary">Kaydet</button>
        </div>
    </form>

    <form method="POST" action="{{ route('settings.uyumsoft.test') }}" style="max-width: 920px;">
        @csrf
        <button type="submit" class="btn btn-outline-primary">Bağlantıyı Test Et</button>
    </form>
@endsection
