@php
    $formId = 'yurtici-test-form-'.$company->id;
    $queryFormId = 'yurtici-query-form-'.$company->id;
    $resultId = 'yurtici-test-result-'.$company->id;
@endphp

<div class="alert alert-info small">
    Yurtiçi <code>createShipment</code> önce <strong>gönderi siparişi</strong> oluşturur.
    Pakete yazdırılacak barkod <strong>cargoKey</strong> değeridir (CODE128).
    Şube bu barkodu okutarak gönderiyi kabul eder. Halka açık takip numarası (<code>docId</code>) genelde şube kabulünden sonra gelir.
</div>

<div class="mb-4">
    <h3 class="h6 mb-2">Bağlantı testi</h3>
    <p class="small eticart-muted mb-2">Kimlik bilgilerinin SOAP endpoint'e ulaşıp ulaşmadığını kontrol eder.</p>
    <div class="d-flex flex-wrap gap-2">
        <form method="POST" action="{{ route('settings.cargo.test-yurtici') }}" class="d-inline">
            @csrf
            <input type="hidden" name="company_id" value="{{ $company->id }}">
            <input type="hidden" name="payment_type" value="sender">
            <button type="submit" class="btn btn-sm btn-outline-primary">
                <i class="bi bi-plug me-1"></i> Gönderici bağlantı testi
            </button>
        </form>
        <form method="POST" action="{{ route('settings.cargo.test-yurtici') }}" class="d-inline">
            @csrf
            <input type="hidden" name="company_id" value="{{ $company->id }}">
            <input type="hidden" name="payment_type" value="receiver">
            <button type="submit" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-plug me-1"></i> Alıcı bağlantı testi
            </button>
        </form>
    </div>
</div>

<hr>

<h3 class="h6 mb-3">1) Gönderi oluştur + takip çek</h3>
<form id="{{ $formId }}" class="yurtici-shipment-test-form" data-result-target="#{{ $resultId }}">
    @csrf
    <input type="hidden" name="company_id" value="{{ $company->id }}">

    <div class="row g-3">
        <div class="col-md-4">
            <label class="form-label">Ödeme tipi</label>
            <select name="payment_type" class="form-select" required>
                <option value="sender" @selected(($settings['default_payment_type'] ?? 'sender') === 'sender')>Gönderici ödemeli</option>
                <option value="receiver" @selected(($settings['default_payment_type'] ?? '') === 'receiver')>Alıcı ödemeli</option>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label">Kargo anahtarı (cargoKey)</label>
            <input type="text" name="cargo_key" class="form-control" placeholder="Boş bırakılırsa otomatik">
            <div class="form-text">Benzersiz gönderi referansı.</div>
        </div>
        <div class="col-md-4">
            <label class="form-label">Fatura / sipariş no (invoiceKey)</label>
            <input type="text" name="order_number" class="form-control" value="TEST{{ now()->format('ymdHis') }}">
        </div>

        <div class="col-md-6">
            <label class="form-label">Alıcı adı soyadı</label>
            <input type="text" name="receiver_name" class="form-control" required
                   value="Test Alıcı Ad Soyad" minlength="5">
        </div>
        <div class="col-md-6">
            <label class="form-label">Telefon</label>
            <input type="text" name="receiver_phone" class="form-control" required value="05551234567">
            <div class="form-text">11 hane, 0 ile başlamalı.</div>
        </div>

        <div class="col-12">
            <label class="form-label">Adres</label>
            <textarea name="receiver_address" class="form-control" rows="2" required minlength="10">Test Mahallesi Test Sokak No 1 Daire 2</textarea>
        </div>

        <div class="col-md-4">
            <label class="form-label">İl</label>
            <input type="text" name="receiver_city" class="form-control" required value="İSTANBUL">
        </div>
        <div class="col-md-4">
            <label class="form-label">İlçe</label>
            <input type="text" name="receiver_town" class="form-control" required value="KADIKÖY">
        </div>
        <div class="col-md-4">
            <label class="form-label">E-posta</label>
            <input type="email" name="receiver_email" class="form-control" placeholder="opsiyonel">
        </div>

        <div class="col-md-3">
            <label class="form-label">Desi</label>
            <input type="number" name="desi" class="form-control" step="0.01" min="0" placeholder="opsiyonel">
        </div>
        <div class="col-md-3">
            <label class="form-label">Kg</label>
            <input type="number" name="weight" class="form-control" step="0.01" min="0" value="1">
        </div>
        <div class="col-md-3">
            <label class="form-label">Paket adedi</label>
            <input type="number" name="cargo_count" class="form-control" min="1" max="99" value="1">
        </div>
        <div class="col-md-3">
            <label class="form-label">Müşteri kodu (custProdId)</label>
            <input type="text" class="form-control" value="{{ $settings['customer_code'] ?? '' }}" disabled>
            <div class="form-text">Ayarlardan okunur.</div>
        </div>

        <div class="col-12">
            <label class="form-label">Açıklama / not</label>
            <input type="text" name="notes" class="form-control" maxlength="500" placeholder="description / specialField3">
        </div>

        <div class="col-md-4">
            <label class="form-label">specialField1</label>
            <input type="text" name="special_field_1" class="form-control" placeholder="xx$xxx# formatı">
        </div>
        <div class="col-md-4">
            <label class="form-label">specialField2</label>
            <input type="text" name="special_field_2" class="form-control">
        </div>
        <div class="col-md-4">
            <label class="form-label">specialField3</label>
            <input type="text" name="special_field_3" class="form-control">
        </div>
    </div>

    <div class="d-flex align-items-center gap-2 mt-3">
        <button type="submit" class="btn btn-primary">
            <i class="bi bi-send me-1"></i> Gönder ve Takip Numarası Çek
        </button>
        <span class="small eticart-muted yurtici-test-status"></span>
    </div>
</form>

<hr class="my-4">

<h3 class="h6 mb-3">2) Mevcut cargoKey ile takip sorgula</h3>
<form id="{{ $queryFormId }}" class="yurtici-query-test-form" data-result-target="#{{ $resultId }}">
    @csrf
    <input type="hidden" name="company_id" value="{{ $company->id }}">
    <div class="row g-3 align-items-end">
        <div class="col-md-3">
            <label class="form-label">Ödeme tipi</label>
            <select name="payment_type" class="form-select" required>
                <option value="sender" @selected(($settings['default_payment_type'] ?? 'sender') === 'sender')>Gönderici ödemeli</option>
                <option value="receiver" @selected(($settings['default_payment_type'] ?? '') === 'receiver')>Alıcı ödemeli</option>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label">Anahtar tipi</label>
            <select name="key_type" class="form-select">
                <option value="0">cargoKey</option>
                <option value="1">invoiceKey</option>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label">Anahtar</label>
            <input type="text" name="key" class="form-control" required placeholder="YK260810...">
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn btn-outline-primary w-100">Sorgula</button>
        </div>
    </div>
    <div class="small eticart-muted mt-2 yurtici-query-status"></div>
</form>

<div id="{{ $resultId }}" class="mt-4 d-none">
    <h3 class="h6 mb-2">Sonuç</h3>
    <div class="yurtici-test-summary mb-2"></div>

    <div class="yurtici-label-preview eticart-card p-3 mb-3 d-none">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
            <div>
                <h3 class="h6 mb-1">3) Kargo barkodu / etiket</h3>
                <p class="small eticart-muted mb-0">
                    Yazdırılan barkod = <code>cargoKey</code> (CODE128). Yurtiçi şubesi bu değeri okur.
                </p>
            </div>
            <a href="#" class="btn btn-sm btn-primary yurtici-print-label-btn" target="_blank" rel="noopener noreferrer">
                <i class="bi bi-printer me-1"></i> Barkod Yazdır
            </a>
        </div>
        <div class="text-center border rounded p-3 bg-white">
            <canvas class="yurtici-barcode-canvas"></canvas>
            <div class="fw-semibold mt-2 yurtici-barcode-text font-monospace"></div>
        </div>
    </div>

    <ul class="nav nav-pills mb-2" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" data-bs-toggle="pill" data-bs-target="#{{ $resultId }}-parsed" type="button" role="tab">Özet</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" data-bs-toggle="pill" data-bs-target="#{{ $resultId }}-xml" type="button" role="tab">Ham XML</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" data-bs-toggle="pill" data-bs-target="#{{ $resultId }}-request" type="button" role="tab">Gönderilen veri</button>
        </li>
    </ul>
    <div class="tab-content">
        <div class="tab-pane fade show active" id="{{ $resultId }}-parsed" role="tabpanel">
            <pre class="eticart-card p-3 small mb-0 yurtici-test-parsed" style="max-height:420px;overflow:auto;"></pre>
        </div>
        <div class="tab-pane fade" id="{{ $resultId }}-xml" role="tabpanel">
            <pre class="eticart-card p-3 small mb-0 yurtici-test-xml" style="max-height:420px;overflow:auto;"></pre>
        </div>
        <div class="tab-pane fade" id="{{ $resultId }}-request" role="tabpanel">
            <pre class="eticart-card p-3 small mb-0 yurtici-test-request" style="max-height:420px;overflow:auto;"></pre>
        </div>
    </div>
</div>
