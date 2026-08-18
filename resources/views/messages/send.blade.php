@extends('layouts.app')

@section('title', 'Mesaj Gönder')

@push('styles')
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
@endpush

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">Mesaj Gönder</h1>
            <p class="eticart-muted mb-0">Müşteriye SMS veya e-posta gönderin; tüm gönderimler kayıtlara işlenir.</p>
        </div>
    </div>

    <div class="eticart-card p-3" style="max-width: 820px;">
        <form method="POST" action="{{ route('messages.send.store') }}" id="messageSendForm">
            @csrf

            <div class="mb-3">
                <label class="form-label">Müşteri</label>
                <select name="customer_id" id="customerSelect" class="form-select" required></select>
            </div>

            <div class="mb-3">
                <label class="form-label">Gönderim tipi</label>
                <select name="channel" id="channelSelect" class="form-select" required>
                    <option value="sms" @selected(old('channel', 'sms') === 'sms')>SMS</option>
                    <option value="mail" @selected(old('channel') === 'mail')>E-posta</option>
                </select>
            </div>

            <div class="mb-3">
                <label class="form-label">Gönderim biçimi</label>
                <select name="mode" id="modeSelect" class="form-select" required>
                    <option value="template" @selected(old('mode', 'template') === 'template')>Şablon</option>
                    <option value="manual" @selected(old('mode') === 'manual')>Manuel</option>
                </select>
            </div>

            <div class="mb-3" id="templateWrap">
                <label class="form-label">Şablon</label>
                <select name="template_slug" id="templateSelect" class="form-select">
                    @foreach ($smsTemplates as $template)
                        <option value="{{ $template['slug'] }}" data-channel="sms">{{ $template['label'] }}</option>
                    @endforeach
                    @foreach ($mailTemplates as $template)
                        <option value="{{ $template['slug'] }}" data-channel="mail">{{ $template['label'] }}</option>
                    @endforeach
                </select>
            </div>

            <div class="mb-3 d-none" id="manualSmsWrap">
                <label class="form-label">SMS metni</label>
                <textarea name="manual_message" rows="4" class="form-control" maxlength="1000">{{ old('manual_message') }}</textarea>
            </div>

            <div class="mb-3 d-none" id="manualMailWrap">
                <label class="form-label">Mail konusu</label>
                <input type="text" name="manual_subject" class="form-control" value="{{ old('manual_subject') }}" maxlength="255">
                <label class="form-label mt-2">Mail içeriği</label>
                <textarea name="manual_body" rows="6" class="form-control">{{ old('manual_body') }}</textarea>
            </div>

            <div class="alert alert-secondary small d-none" id="previewBox"></div>

            <div class="alert alert-warning small @if($smsConfigured) d-none @endif" id="smsDisabledAlert">
                SMS ayarları tanımlı değil. Ayarlar → SMS bölümünü doldurun.
            </div>

            <button type="submit" class="btn btn-primary" id="sendBtn">Gönder</button>
        </form>
    </div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const smsConfigured = @json($smsConfigured);
    const previewUrlBase = @json(url('/messages/customers'));
    const searchUrl = @json(route('messages.customers-search'));

    const channelSelect = document.getElementById('channelSelect');
    const modeSelect = document.getElementById('modeSelect');
    const templateSelect = document.getElementById('templateSelect');
    const manualSmsWrap = document.getElementById('manualSmsWrap');
    const manualMailWrap = document.getElementById('manualMailWrap');
    const templateWrap = document.getElementById('templateWrap');
    const previewBox = document.getElementById('previewBox');
    const sendBtn = document.getElementById('sendBtn');
    const smsDisabledAlert = document.getElementById('smsDisabledAlert');

    let previewCache = null;

    $('#customerSelect').select2({
        theme: 'bootstrap-5',
        placeholder: 'Müşteri ara (ad, e-posta, telefon)',
        minimumInputLength: 0,
        ajax: {
            url: searchUrl,
            dataType: 'json',
            delay: 250,
            data: params => ({ q: params.term || '' }),
            processResults: data => ({ results: data.results || [] }),
        },
        width: '100%',
    }).on('change', loadPreview);

    function filterTemplates() {
        const channel = channelSelect.value;
        Array.from(templateSelect.options).forEach(option => {
            option.hidden = option.dataset.channel !== channel;
        });
        const firstVisible = Array.from(templateSelect.options).find(o => !o.hidden);
        if (firstVisible) templateSelect.value = firstVisible.value;
    }

    function toggleMode() {
        const mode = modeSelect.value;
        const channel = channelSelect.value;
        templateWrap.classList.toggle('d-none', mode === 'manual');
        manualSmsWrap.classList.toggle('d-none', mode !== 'manual' || channel !== 'sms');
        manualMailWrap.classList.toggle('d-none', mode !== 'manual' || channel !== 'mail');
    }

    function toggleChannel() {
        filterTemplates();
        toggleMode();
        const isSms = channelSelect.value === 'sms';
        smsDisabledAlert.classList.toggle('d-none', !isSms || smsConfigured);
        sendBtn.disabled = isSms && !smsConfigured;
    }

    async function loadPreview() {
        const customerId = $('#customerSelect').val();
        previewBox.classList.add('d-none');
        previewCache = null;
        if (!customerId) return;

        try {
            const res = await fetch(`${previewUrlBase}/${customerId}/preview`, {
                headers: { 'Accept': 'application/json' },
            });
            previewCache = await res.json();
            renderPreview();
        } catch (e) {
            previewBox.textContent = 'Önizleme yüklenemedi.';
            previewBox.classList.remove('d-none');
        }
    }

    function renderPreview() {
        if (!previewCache) return;
        const channel = channelSelect.value;
        const mode = modeSelect.value;
        if (mode === 'manual') {
            previewBox.classList.add('d-none');
            return;
        }

        const slug = templateSelect.value;
        if (channel === 'sms') {
            const text = previewCache.sms_preview?.[slug] || '';
            previewBox.innerHTML = `<strong>Önizleme SMS:</strong><br>${text.replace(/\n/g, '<br>')}`;
        } else {
            const mail = previewCache.mail_preview?.[slug] || {};
            previewBox.innerHTML = `<strong>Önizleme mail:</strong><br><em>${mail.subject || ''}</em><br>${mail.body || ''}`;
        }
        previewBox.classList.remove('d-none');
    }

    channelSelect.addEventListener('change', () => { toggleChannel(); renderPreview(); });
    modeSelect.addEventListener('change', () => { toggleMode(); renderPreview(); });
    templateSelect.addEventListener('change', renderPreview);

    toggleChannel();
});
</script>
@endpush
