@extends('layouts.app')

@section('title', 'Genel Ayarlar')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">Genel Ayarlar</h1>
            <p class="eticart-muted mb-0">Sistem adı, gönderen firma ve kargo barkod etiket bilgileri.</p>
        </div>
        <a href="{{ route('settings.index') }}" class="btn btn-outline-secondary">Geri</a>
    </div>

    <div class="eticart-card p-3" style="max-width: 760px;">
        <form method="POST" action="{{ route('settings.general.update') }}">
            @csrf
            @method('PUT')

            <h2 class="h6 mb-3">Sistem</h2>
            <div class="row g-3 mb-4">
                <div class="col-12 col-md-8">
                    <label class="form-label">Sistem adı</label>
                    <input
                        type="text"
                        name="general_app_name"
                        class="form-control @error('general_app_name') is-invalid @enderror"
                        value="{{ old('general_app_name', $settings['general_app_name'] ?? config('app.name', 'EtiCart')) }}"
                        placeholder="Örn. EtiCart"
                        required
                        maxlength="80"
                    >
                    @error('general_app_name')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                    <div class="form-text">Panel başlığı ve menü logosu. Müşteri maillerinde kullanılmaz.</div>
                </div>
            </div>

            <h2 class="h6 mb-3">Gönderen firma</h2>
            <div class="row g-3 mb-4">
                <div class="col-12">
                    <label class="form-label">Firma adı</label>
                    <input type="text" name="general_company_name" class="form-control" value="{{ old('general_company_name', $settings['general_company_name'] ?? '') }}" placeholder="Örn. Özşeyma Tekstil">
                    <div class="form-text">Müşteri e-postalarının konusu, imzası ve altbilgisinde bu ad görünür.</div>
                </div>
                <div class="col-12">
                    <label class="form-label">Web sitesi</label>
                    <input type="url" name="general_website_url" class="form-control" value="{{ old('general_website_url', $settings['general_website_url'] ?? '') }}" placeholder="https://www.ornek.com">
                    <div class="form-text">Mail içindeki “Mağazamız” linki. Boşsa Shopify mağaza adresi kullanılır. Hesabım linki: site/account</div>
                </div>
                <div class="col-12">
                    <label class="form-label">Adres</label>
                    <textarea name="general_company_address" rows="2" class="form-control" placeholder="Mahalle, sokak, ilçe / il">{{ old('general_company_address', $settings['general_company_address'] ?? '') }}</textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Telefon</label>
                    <input type="text" name="general_company_phone" class="form-control" value="{{ old('general_company_phone', $settings['general_company_phone'] ?? '') }}" placeholder="0212 000 00 00">
                </div>
            </div>

            <h2 class="h6 mb-3">İade ve değişim</h2>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">İade kargo firması</label>
                    <input type="text" name="general_return_cargo_name" class="form-control" value="{{ old('general_return_cargo_name', $settings['general_return_cargo_name'] ?? '') }}" placeholder="Yurtiçi Kargo">
                </div>
                <div class="col-md-6">
                    <label class="form-label">İade müşteri / anlaşma no</label>
                    <input type="text" name="general_return_cargo_code" class="form-control" value="{{ old('general_return_cargo_code', $settings['general_return_cargo_code'] ?? '') }}" placeholder="216625941">
                </div>
            </div>
            <p class="form-text mt-2">
                Etikette şöyle görünür: İade ve değişim durumlarında ‘Firma Adı 216625941’ numarası ile ürünlerinizi ücretsiz olarak geri gönderebilirsiniz.
            </p>

            <button type="submit" class="btn btn-primary mt-3">Kaydet</button>
        </form>
    </div>
@endsection
