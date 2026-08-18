@extends('layouts.app')

@section('title', 'Mail Şablonları')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">Mail Şablonları</h1>
            <p class="eticart-muted mb-0">
                Bu içerik ana mail şablonunun gövdesine yerleşir. Değişkenler:
                <code>@php echo '{{customer_name}}'; @endphp</code>
                <code>@php echo '{{order_number}}'; @endphp</code>
                <code>@php echo '{{total_price}}'; @endphp</code>
                <code>@php echo '{{tracking_number}}'; @endphp</code>
                <code>@php echo '{{tracking_url}}'; @endphp</code>
                <code>@php echo '{{cargo_company}}'; @endphp</code>
                <code>@php echo '{{status}}'; @endphp</code>
            </p>
        </div>
        <a href="{{ route('settings.index') }}" class="btn btn-outline-secondary">Geri</a>
    </div>

    <div class="accordion" id="mailTemplatesAccordion">
        @foreach ($templates as $template)
            <div class="accordion-item eticart-card mb-2 border-0">
                <h2 class="accordion-header">
                    <button class="accordion-button {{ $loop->first ? '' : 'collapsed' }}" type="button" data-bs-toggle="collapse" data-bs-target="#mailTpl{{ $template->id }}">
                        <span class="fw-semibold">{{ $template->name }}</span>
                        <span class="ms-2 small eticart-muted">{{ $template->slug }}</span>
                    </button>
                </h2>
                <div id="mailTpl{{ $template->id }}" class="accordion-collapse collapse {{ $loop->first ? 'show' : '' }}" data-bs-parent="#mailTemplatesAccordion">
                    <div class="accordion-body">
                        <form method="POST" action="{{ route('settings.templates.mail.update', $template) }}" class="mail-template-form">
                            @csrf
                            @method('PUT')
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Ad</label>
                                    <input type="text" name="name" class="form-control" value="{{ $template->name }}" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Konu</label>
                                    <input type="text" name="subject" class="form-control" value="{{ $template->subject }}" required>
                                </div>
                                <div class="col-12">
                                    <label class="form-label">İçerik</label>
                                    <textarea name="body" id="mail-body-{{ $template->id }}" class="mail-template-editor form-control" rows="8">{{ $template->body }}</textarea>
                                </div>
                                <div class="col-12">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="is_active" value="1" id="mail_{{ $template->id }}" @checked($template->is_active)>
                                        <label class="form-check-label" for="mail_{{ $template->id }}">Aktif</label>
                                    </div>
                                </div>
                            </div>
                            <button class="btn btn-primary btn-sm mt-3" type="submit">Kaydet</button>
                        </form>

                        <hr>
                        <div class="row g-2 align-items-end">
                            <div class="col-md-6">
                                <label class="form-label">Test mail adresi</label>
                                <input type="email" class="form-control mail-test-email" placeholder="ornek@firma.com" data-template="{{ $template->id }}">
                            </div>
                            <div class="col-md-3">
                                <button type="button" class="btn btn-outline-secondary mail-test-btn" data-url="{{ route('settings.templates.mail.test', $template) }}">
                                    Test maili gönder
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
<script src="https://cdn.jsdelivr.net/npm/tinymce@6.8.4/tinymce.min.js" referrerpolicy="origin"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    tinymce.init({
        selector: '.mail-template-editor',
        menubar: false,
        branding: false,
        plugins: 'link image lists table code autoresize',
        toolbar: 'undo redo | fontfamily fontsize | bold italic underline | forecolor | alignleft aligncenter alignright | bullist numlist | link image table | removeformat',
        font_family_formats: 'Arial=arial,helvetica,sans-serif; Georgia=georgia,serif; Tahoma=tahoma,sans-serif; Times New Roman=times new roman,times,serif; Verdana=verdana,sans-serif',
        height: 280,
        convert_urls: false,
        image_title: true,
        automatic_uploads: false,
        file_picker_types: 'image',
        setup(editor) {
            editor.on('change', () => editor.save());
        }
    });

    document.querySelectorAll('.mail-template-form').forEach((form) => {
        form.addEventListener('submit', () => tinymce.triggerSave());
    });

    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

    document.querySelectorAll('.mail-test-btn').forEach((btn) => {
        btn.addEventListener('click', async () => {
            const wrap = btn.closest('.accordion-body');
            const email = wrap?.querySelector('.mail-test-email')?.value?.trim();
            if (! email) {
                Swal.fire({ icon: 'info', title: 'E-posta girin', text: 'Test için bir alıcı adresi yazın.' });
                return;
            }

            Swal.fire({
                title: 'Test maili gönderiliyor',
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
                    body: JSON.stringify({ email }),
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
