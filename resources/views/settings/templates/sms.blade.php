@extends('layouts.app')

@section('title', 'SMS Şablonları')

@section('content')
    @php
        $tokens = ['customer_name', 'order_number', 'total_price', 'currency', 'tracking_number', 'tracking_url', 'cargo_company', 'invoice_no', 'invoice_url', 'return_cargo_name', 'return_cargo_code'];
        $sample = [
            'customer_name' => 'Ahmet Solmaz',
            'order_number' => '#23423434',
            'total_price' => '4500',
            'currency' => 'TL',
            'tracking_number' => '454221545',
            'tracking_url' => 'https://yurticikargo.com/takip/454221545',
            'cargo_company' => 'Yurtiçi Kargo',
            'invoice_no' => 'ETF2026001',
            'invoice_url' => 'https://example.com/fatura/ornek',
            'return_cargo_name' => 'Yurtiçi Kargo',
            'return_cargo_code' => '216625941',
        ];
    @endphp

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">SMS Şablonları</h1>
            <p class="eticart-muted mb-0">
                Müşteri SMS’lerinde kullanılabilir değişkenler:
                @foreach ($tokens as $token)
                    <code>@php echo htmlspecialchars('{{'.$token.'}}', ENT_QUOTES, 'UTF-8'); @endphp</code>
                @endforeach
            </p>
        </div>
        <a href="{{ route('settings.index') }}" class="btn btn-outline-secondary">Geri</a>
    </div>

    <div class="accordion" id="smsTemplatesAccordion">
        @foreach ($templates as $template)
            <div class="accordion-item eticart-card mb-2 border-0 sms-template-card"
                 x-data="{ body: @js($template->body), sample: @js($sample) }">
                <h2 class="accordion-header">
                    <button class="accordion-button {{ $loop->first ? '' : 'collapsed' }}" type="button" data-bs-toggle="collapse" data-bs-target="#smsTpl{{ $template->id }}">
                        <span class="fw-semibold">{{ $template->name }}</span>
                        <span class="ms-2 small eticart-muted">{{ $template->slug }}</span>
                        @if (in_array($template->slug, $predefinedSlugs ?? [], true))
                            <span class="badge text-bg-light border ms-2">Ön tanımlı</span>
                        @endif
                    </button>
                </h2>
                <div id="smsTpl{{ $template->id }}" class="accordion-collapse collapse {{ $loop->first ? 'show' : '' }}" data-bs-parent="#smsTemplatesAccordion">
                    <div class="accordion-body">
                        <form method="POST" action="{{ route('settings.templates.sms.update', $template) }}">
                            @csrf
                            @method('PUT')
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Ad</label>
                                    <input type="text" name="name" class="form-control" value="{{ $template->name }}" required>
                                </div>
                                <div class="col-12">
                                    <label class="form-label">İçerik</label>
                                    <div class="d-flex flex-wrap gap-1 mb-2">
                                        @foreach ($tokens as $token)
                                            <button type="button" class="btn btn-sm btn-outline-secondary js-insert-token" data-token="{{ $token }}">
                                                @php echo htmlspecialchars('{{'.$token.'}}', ENT_QUOTES, 'UTF-8'); @endphp
                                            </button>
                                        @endforeach
                                    </div>
                                    <textarea name="body" class="form-control" rows="4" x-model="body" maxlength="500" required></textarea>
                                    <div class="form-text"><span x-text="body.length"></span>/500</div>
                                </div>
                                <div class="col-12">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="is_active" value="1" id="sms_{{ $template->id }}" @checked($template->is_active)>
                                        <label class="form-check-label" for="sms_{{ $template->id }}">Aktif</label>
                                    </div>
                                </div>
                            </div>
                            <button class="btn btn-primary btn-sm mt-3" type="submit">Kaydet</button>
                        </form>

                        <div class="mt-3 p-3 bg-light rounded small">
                            <div class="fw-semibold mb-1">Önizleme (örnek veri)</div>
                            <div x-text="window.previewSmsTemplate(body, sample)"></div>
                        </div>

                        <hr>
                        <div class="row g-2 align-items-end">
                            <div class="col-md-6">
                                <label class="form-label">Test telefon</label>
                                <input type="text" class="form-control sms-test-phone" placeholder="05xx xxx xx xx">
                            </div>
                            <div class="col-md-3">
                                <button type="button" class="btn btn-outline-secondary sms-test-btn" data-url="{{ route('settings.templates.sms.test', $template) }}">
                                    Test SMS gönder
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
window.previewSmsTemplate = function (body, sample) {
    let text = body || '';
    Object.keys(sample || {}).forEach((key) => {
        text = text.split('{' + '{' + key + '}' + '}').join(sample[key]);
    });
    return text;
};

document.addEventListener('DOMContentLoaded', () => {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

    document.querySelectorAll('.js-insert-token').forEach((btn) => {
        btn.addEventListener('click', () => {
            const root = btn.closest('[x-data]');
            if (! root || ! window.Alpine) return;
            const data = window.Alpine.$data(root);
            const token = '{{' + btn.dataset.token + '}}';
            data.body = (data.body || '') + ((data.body && ! String(data.body).endsWith(' ')) ? ' ' : '') + token;
        });
    });

    document.querySelectorAll('.sms-test-btn').forEach((btn) => {
        btn.addEventListener('click', async () => {
            const wrap = btn.closest('.accordion-body');
            const phone = wrap?.querySelector('.sms-test-phone')?.value?.trim();
            if (! phone) {
                Swal.fire({ icon: 'info', title: 'Telefon girin', text: 'Test için bir numara yazın.' });
                return;
            }

            Swal.fire({
                title: 'Test SMS gönderiliyor',
                text: 'Lütfen bekleyiniz…',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading(),
            });

            try {
                const response = await fetch(btn.dataset.url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrf,
                    },
                    body: JSON.stringify({ phone }),
                });
                const payload = await response.json();
                Swal.fire({
                    icon: payload.success ? 'success' : 'error',
                    title: payload.success ? 'Gönderildi' : 'Gönderilemedi',
                    text: payload.message || '',
                });
            } catch (error) {
                Swal.fire({ icon: 'error', title: 'İstek başarısız', text: error.message || 'Beklenmeyen hata' });
            }
        });
    });
});
</script>
@endpush
