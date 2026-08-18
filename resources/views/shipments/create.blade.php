@extends('layouts.app')

@section('title', 'Kargo Oluştur')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">Kargo Oluştur</h1>
            <p class="eticart-muted mb-0">Sipariş: {{ $order->order_number }}</p>
        </div>
        <a href="{{ route('orders.show', $order) }}" class="btn btn-outline-secondary">Geri</a>
    </div>

    <div class="eticart-card p-3" style="max-width: 820px;">
        <form method="POST" action="{{ route('shipments.store', $order) }}">
            @csrf

            <div class="mb-3">
                <label class="form-label">Kargo firması</label>
                <select name="cargo_company_id" id="cargoCompanySelect" class="form-select @error('cargo_company_id') is-invalid @enderror" required>
                    <option value="">Seçiniz</option>
                    @foreach ($companies as $company)
                        <option value="{{ $company->id }}"
                                data-provider="{{ $company->provider_type }}"
                                data-default-payment="{{ $company->settings['default_payment_type'] ?? 'sender' }}"
                                @selected(old('cargo_company_id') == $company->id || $company->is_default)>
                            {{ $company->name }} {{ $company->is_active ? '' : '(pasif)' }}
                        </option>
                    @endforeach
                </select>
                @error('cargo_company_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                @if ($companies->isEmpty())
                    <div class="form-text">API bilgisi tanımlı aktif kargo firması yok. Ayarlar → Kargo.</div>
                @endif
            </div>

            <div class="mb-3" id="yurticiPaymentWrap" style="display:none;">
                <label class="form-label">Yurtiçi ödeme tipi</label>
                <select name="payment_type" id="paymentTypeSelect" class="form-select">
                    <option value="sender" @selected(old('payment_type', 'sender') === 'sender')>Gönderici ödemeli</option>
                    <option value="receiver" @selected(old('payment_type') === 'receiver')>Alıcı ödemeli</option>
                </select>
                <div class="form-text">Seçime göre ilgili kullanıcı adı / şifre ile SOAP çağrısı yapılır.</div>
            </div>

            <div class="alert alert-warning small d-none" id="yurticiHint">
                Yurtiçi için alıcı adı (min. 5), adres (min. 10), şehir, <strong>ilçe</strong> ve 11 haneli telefon zorunludur.
                Shopify’dan gelen “Misafir” / boş adres kayıtlarında alanları elle doldurun.
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label">Alıcı adı</label>
                    <input type="text" name="receiver_name" class="form-control" value="{{ old('receiver_name', $order->customer_name) }}" required minlength="5">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Telefon</label>
                    <input type="text" name="receiver_phone" id="receiverPhone" class="form-control" value="{{ old('receiver_phone', $order->customer_phone) }}" placeholder="05551234567">
                </div>
                <div class="col-md-8">
                    <label class="form-label">Adres</label>
                    <textarea name="receiver_address" class="form-control" rows="2" required minlength="10">{{ old('receiver_address', $order->shipping_address) }}</textarea>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Şehir</label>
                    <input type="text" name="receiver_city" class="form-control" value="{{ old('receiver_city', $order->shipping_city) }}">
                </div>
                <div class="col-md-4" id="townWrap" style="display:none;">
                    <label class="form-label">İlçe <span class="text-danger">*</span></label>
                    <input type="text" name="receiver_town" id="receiverTown" class="form-control" value="{{ old('receiver_town') }}" placeholder="Örn. Kadıköy">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Ağırlık (kg)</label>
                    <input type="number" step="0.01" min="0" name="weight" class="form-control" value="{{ old('weight', 1) }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Sigorta</label>
                    <input type="number" step="0.01" min="0" name="insurance" class="form-control" value="{{ old('insurance') }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Kargo ücreti</label>
                    <input type="number" step="0.01" min="0" name="cargo_cost" class="form-control" value="{{ old('cargo_cost') }}">
                </div>
                <div class="col-12">
                    <label class="form-label">Özel not / talimat</label>
                    <textarea name="notes" class="form-control" rows="2">{{ old('notes') }}</textarea>
                </div>
            </div>

            <button type="submit" class="btn btn-primary">Kargo Oluştur</button>
        </form>
    </div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const select = document.getElementById('cargoCompanySelect');
    const paymentWrap = document.getElementById('yurticiPaymentWrap');
    const townWrap = document.getElementById('townWrap');
    const paymentSelect = document.getElementById('paymentTypeSelect');

    const hint = document.getElementById('yurticiHint');
    const townInput = document.getElementById('receiverTown');
    const phoneInput = document.getElementById('receiverPhone');

    const toggle = () => {
        const option = select?.selectedOptions?.[0];
        const provider = option?.getAttribute('data-provider') || '';
        const isYurtici = provider === 'yurtici';
        if (paymentWrap) paymentWrap.style.display = isYurtici ? '' : 'none';
        if (townWrap) townWrap.style.display = isYurtici ? '' : 'none';
        if (hint) hint.classList.toggle('d-none', !isYurtici);
        if (townInput) townInput.required = isYurtici;
        if (phoneInput) phoneInput.required = isYurtici;
        if (isYurtici && paymentSelect && option?.dataset.defaultPayment) {
            paymentSelect.value = option.dataset.defaultPayment;
        }
    };

    select?.addEventListener('change', toggle);
    toggle();
});
</script>
@endpush
