@extends('layouts.app')

@section('title', 'UyumSoft Ayarları')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">UyumSoft Ayarları</h1>
            <p class="eticart-muted mb-0">UyumCloud (EKO) web servis bilgileri.</p>
        </div>
        <a href="{{ route('settings.index') }}" class="btn btn-outline-secondary">Geri</a>
    </div>

    <div class="eticart-card p-3" style="max-width: 820px;">
        <div class="alert alert-info small">
            Entegra ile aynı bilgileri kullanıyorsanız <strong>Base URL</strong> olarak firmanıza özel
            <code>https://api-....eko.uyumcloud.com</code> adresini girin.
            Kullanıcı tipi <strong>WEB Servis</strong> olmalıdır (<code>WEBSERVIS</code>).
            Ürün çekmek için ayrıca <strong>İşyeri Kodu</strong> gerekir (Entegra ayarlarından bakın).
        </div>

        <form method="POST" action="{{ route('settings.uyumsoft.update') }}">
            @csrf
            @method('PUT')

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
            <div class="mb-3">
                <label class="form-label">Base URL</label>
                <input type="url" name="uyumsoft_base_url" class="form-control" value="{{ old('uyumsoft_base_url', $settings['uyumsoft_base_url'] ?? '') }}" placeholder="https://api-firma.eko.uyumcloud.com">
                <div class="form-text">Panelde kaydettiğiniz URL kullanılır; .env içindeki varsayılan adresin önüne geçer.</div>
            </div>

            <button type="submit" class="btn btn-primary">Kaydet</button>
        </form>

        <form method="POST" action="{{ route('settings.uyumsoft.test') }}" class="mt-3">
            @csrf
            <button type="submit" class="btn btn-outline-primary">Bağlantıyı Test Et</button>
        </form>
    </div>
@endsection
