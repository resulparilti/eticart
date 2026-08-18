@extends('layouts.app')

@section('title', 'SMS Ayarları')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">SMS Ayarları</h1>
            <p class="eticart-muted mb-0">Local için provider: log.</p>
        </div>
        <a href="{{ route('settings.index') }}" class="btn btn-outline-secondary">Geri</a>
    </div>

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="eticart-card p-3">
                <form method="POST" action="{{ route('settings.sms.update') }}">
                    @csrf
                    @method('PUT')

                    <div class="mb-3">
                        <label class="form-label">Provider</label>
                        <select name="sms_provider" class="form-select" required>
                            @foreach (['log' => 'Log (Local)', 'netgsm' => 'Netgsm', 'generic' => 'Generic API'] as $value => $label)
                                <option value="{{ $value }}" @selected(old('sms_provider', $settings['sms_provider'] ?? 'log') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">API Key / Usercode</label>
                        <input type="text" name="sms_api_key" class="form-control" value="{{ old('sms_api_key', $settings['sms_api_key'] ?? '') }}">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">API Secret / Password</label>
                        <input type="password" name="sms_api_secret" class="form-control" value="{{ old('sms_api_secret', $settings['sms_api_secret'] ?? '') }}">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Header / Title</label>
                        <input type="text" name="sms_header" class="form-control" value="{{ old('sms_header', $settings['sms_header'] ?? 'ETICART') }}">
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="sms_normalize_zero" value="1" id="sms_normalize_zero" @checked(($settings['sms_normalize_zero'] ?? '1') === '1')>
                        <label class="form-check-label" for="sms_normalize_zero">Baştaki 0'ı 90'a çevir</label>
                    </div>

                    <button type="submit" class="btn btn-primary">Kaydet</button>
                </form>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="eticart-card p-3">
                <h2 class="h6 mb-3">Test SMS</h2>
                <form method="POST" action="{{ route('settings.sms.test') }}">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label">Telefon</label>
                        <input type="text" name="test_phone" class="form-control" required placeholder="05xxxxxxxxx">
                    </div>
                    <button type="submit" class="btn btn-outline-primary">Test Gönder</button>
                </form>
            </div>
        </div>
    </div>
@endsection
