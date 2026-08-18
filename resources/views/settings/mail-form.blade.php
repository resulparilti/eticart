@extends('layouts.app')

@section('title', 'Mail Ayarları')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">Mail Ayarları</h1>
            <p class="eticart-muted mb-0">
                Aktif mailer: <code>{{ $settings['mail_mailer'] ?? config('mail.default') }}</code>
            </p>
        </div>
        <a href="{{ route('settings.index') }}" class="btn btn-outline-secondary">Geri</a>
    </div>
 

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="eticart-card p-3">
                <form method="POST" action="{{ route('settings.mail.update') }}" enctype="multipart/form-data">
                    @csrf
                    @method('PUT')

                    <div class="row g-3">
                        <div class="col-12">
                            <h2 class="h6 mb-0">Gönderim</h2>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Mailer</label>
                            <select name="mail_mailer" class="form-select" required>
                                <option value="log" @selected(old('mail_mailer', $settings['mail_mailer'] ?? 'log') === 'log')>log (gerçek mail gitmez)</option>
                                <option value="sendmail" @selected(old('mail_mailer', $settings['mail_mailer'] ?? '') === 'sendmail')>sendmail (cPanel — kurumsal mailler için önerilir)</option>
                                <option value="smtp" @selected(old('mail_mailer', $settings['mail_mailer'] ?? '') === 'smtp')>smtp (Gmail / harici SMTP)</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">From Email</label>
                            <input type="email" name="mail_from_address" class="form-control" value="{{ old('mail_from_address', $settings['mail_from_address'] ?? '') }}">
                            <div class="form-text">cPanel’de açtığınız adres: örn. info@pariltisoft.com</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">From Name</label>
                            <input type="text" name="mail_from_name" class="form-control" value="{{ old('mail_from_name', $settings['mail_from_name'] ?? '') }}">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">SMTP Host</label>
                            <input type="text" name="mail_smtp_host" class="form-control" value="{{ old('mail_smtp_host', $settings['mail_smtp_host'] ?? '') }}" placeholder="mail.pariltisoft.com">
                            <div class="form-text">Yalnızca Mailer = smtp ise gerekli. sendmail seçiliyse boş bırakılabilir.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">SMTP Port</label>
                            <select name="mail_smtp_port" class="form-select">
                                @foreach ([25, 465, 587, 2525] as $port)
                                    <option value="{{ $port }}" @selected((string) old('mail_smtp_port', $settings['mail_smtp_port'] ?? '587') === (string) $port)>{{ $port }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">SMTP Username</label>
                            <input type="text" name="mail_smtp_username" class="form-control" value="{{ old('mail_smtp_username', $settings['mail_smtp_username'] ?? '') }}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">SMTP Password</label>
                            <input type="password" name="mail_smtp_password" class="form-control" value="" autocomplete="new-password" placeholder="{{ filled($settings['mail_smtp_password'] ?? null) ? 'Kayıtlı — değiştirmek için yazın' : 'SMTP şifresi' }}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Encryption</label>
                            <select name="mail_smtp_encryption" class="form-select">
                                <option value="null" @selected(old('mail_smtp_encryption', $settings['mail_smtp_encryption'] ?? '') === '')>Yok</option>
                                <option value="tls" @selected(old('mail_smtp_encryption', $settings['mail_smtp_encryption'] ?? '') === 'tls')>TLS (587)</option>
                                <option value="ssl" @selected(old('mail_smtp_encryption', $settings['mail_smtp_encryption'] ?? '') === 'ssl')>SSL (465)</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="mail_attach_invoice" id="mail_attach_invoice" value="1" @checked(old('mail_attach_invoice', $settings['mail_attach_invoice'] ?? '0') === '1')>
                                <label class="form-check-label" for="mail_attach_invoice">Fatura PDF’sini e-postaya ekle</label>
                            </div>
                         
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Gönderimler arası bekleme</label>
                            <select name="mail_send_interval_minutes" class="form-select">
                                @foreach ([2, 3, 4, 5, 6, 8, 10] as $mins)
                                    <option value="{{ $mins }}" @selected((string) old('mail_send_interval_minutes', $settings['mail_send_interval_minutes'] ?? '3') === (string) $mins)>{{ $mins }} dakika</option>
                                @endforeach
                            </select>
                            <div class="form-text">Hosting en az birkaç dakika ara istedi. Varsayılan 3 dakikadır.</div>
                        </div>
                        <div class="col-12 pt-2">
                            <h2 class="h6 mb-1">Mail tasarımı</h2>
                            <p class="small eticart-muted mb-0">Kargo / fatura e-postaları bu marka ayarlarını kullanır.</p>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Site / marka adı</label>
                            <input type="text" name="mail_brand_name" class="form-control" value="{{ old('mail_brand_name', $settings['mail_brand_name'] ?? ($brandName ?? '')) }}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Logo</label>
                            <input type="file" name="mail_logo" class="form-control" accept=".png,.jpg,.jpeg,.webp,.svg">
                            @if (filled($settings['mail_logo_path'] ?? null))
                                <div class="form-text">Kayıtlı logo var. Yeni dosya yüklerseniz değişir.</div>
                            @endif
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Başlık rengi</label>
                            <input type="color" name="mail_header_bg" class="form-control form-control-color w-100" value="{{ old('mail_header_bg', $settings['mail_header_bg'] ?? '#0f2a3d') }}">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Başlık yazı rengi</label>
                            <input type="color" name="mail_header_text" class="form-control form-control-color w-100" value="{{ old('mail_header_text', $settings['mail_header_text'] ?? '#ffffff') }}">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Yazı rengi</label>
                            <input type="color" name="mail_text_color" class="form-control form-control-color w-100" value="{{ old('mail_text_color', $settings['mail_text_color'] ?? '#142433') }}">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">İkincil yazı rengi</label>
                            <input type="color" name="mail_muted_color" class="form-control form-control-color w-100" value="{{ old('mail_muted_color', $settings['mail_muted_color'] ?? '#5b6b7c') }}">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Bağlantı rengi</label>
                            <input type="color" name="mail_link_color" class="form-control form-control-color w-100" value="{{ old('mail_link_color', $settings['mail_link_color'] ?? '#c45c26') }}">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Buton rengi</label>
                            <input type="color" name="mail_button_bg" class="form-control form-control-color w-100" value="{{ old('mail_button_bg', $settings['mail_button_bg'] ?? '#0f2a3d') }}">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Buton yazı rengi</label>
                            <input type="color" name="mail_button_text" class="form-control form-control-color w-100" value="{{ old('mail_button_text', $settings['mail_button_text'] ?? '#ffffff') }}">
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary mt-3">Kaydet</button>
                </form>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="eticart-card p-3">
                <h2 class="h6 mb-3">Test E-posta</h2>
                <p class="small eticart-muted">Kaydettikten sonra önce Gmail’e, sonra bir kurumsal adrese test gönderin.</p>
                <form method="POST" action="{{ route('settings.mail.test') }}">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label">Alıcı</label>
                        <input type="email" name="test_email" class="form-control" required placeholder="ornek@mail.com">
                    </div>
                    <button type="submit" class="btn btn-outline-primary">Test Gönder</button>
                </form>
            </div>
        </div>
    </div>
@endsection
