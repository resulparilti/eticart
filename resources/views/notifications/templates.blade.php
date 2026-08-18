@extends('layouts.app')

@section('title', 'Bildirim Şablonları')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">Şablonlar</h1>
            <p class="eticart-muted mb-0">Mail ve SMS şablonlarını düzenleyin. Değişkenler: @{{customer_name}}, @{{order_number}}, @{{total_price}}, @{{tracking_number}}, @{{status}}</p>
        </div>
        <a href="{{ route('notifications.index') }}" class="btn btn-outline-secondary">Geri</a>
    </div>

    <div class="row g-3">
        <div class="col-12 col-xl-6">
            <h2 class="h5 mb-3">Mail Şablonları</h2>
            @foreach ($mailTemplates as $template)
                <div class="eticart-card p-3 mb-3">
                    <form method="POST" action="{{ route('notifications.templates.mail.update', $template) }}">
                        @csrf
                        @method('PUT')
                        <div class="mb-2">
                            <label class="form-label">Ad</label>
                            <input type="text" name="name" class="form-control" value="{{ $template->name }}" required>
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Slug</label>
                            <input type="text" class="form-control" value="{{ $template->slug }}" disabled>
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Konu</label>
                            <input type="text" name="subject" class="form-control" value="{{ $template->subject }}" required>
                        </div>
                        <div class="mb-2">
                            <label class="form-label">İçerik</label>
                            <textarea name="body" class="form-control" rows="4" required>{{ $template->body }}</textarea>
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" name="is_active" value="1" id="mail_active_{{ $template->id }}" @checked($template->is_active)>
                            <label class="form-check-label" for="mail_active_{{ $template->id }}">Aktif</label>
                        </div>
                        <button class="btn btn-primary btn-sm" type="submit">Kaydet</button>
                    </form>
                </div>
            @endforeach
        </div>

        <div class="col-12 col-xl-6">
            <h2 class="h5 mb-3">SMS Şablonları</h2>
            @foreach ($smsTemplates as $template)
                <div class="eticart-card p-3 mb-3">
                    <form method="POST" action="{{ route('notifications.templates.sms.update', $template) }}" x-data="{ body: @js($template->body) }">
                        @csrf
                        @method('PUT')
                        <div class="mb-2">
                            <label class="form-label">Ad</label>
                            <input type="text" name="name" class="form-control" value="{{ $template->name }}" required>
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Slug</label>
                            <input type="text" class="form-control" value="{{ $template->slug }}" disabled>
                        </div>
                        <div class="mb-2">
                            <label class="form-label">İçerik</label>
                            <textarea name="body" class="form-control" rows="4" x-model="body" maxlength="500" required>{{ $template->body }}</textarea>
                            <div class="form-text"><span x-text="body.length"></span>/500 karakter</div>
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" name="is_active" value="1" id="sms_active_{{ $template->id }}" @checked($template->is_active)>
                            <label class="form-check-label" for="sms_active_{{ $template->id }}">Aktif</label>
                        </div>
                        <button class="btn btn-primary btn-sm" type="submit">Kaydet</button>
                    </form>
                </div>
            @endforeach
        </div>
    </div>
@endsection
