<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $appBrandName ?? config('app.name', 'EtiCart') }} — Giriş</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <x-production-assets />
</head>
<body>
    <div class="auth-wrap">
        <div class="auth-card">
            <div class="text-center mb-4">
                @php
                    $brand = $appBrandName ?? \App\Models\Setting::appName();
                @endphp
                <div class="auth-card__brand">
                    @if (preg_match('/^(Eti)(Cart)$/iu', $brand, $parts) === 1)
                        {{ $parts[1] }}<span>{{ $parts[2] }}</span>
                    @else
                        {{ $brand }}
                    @endif
                </div>
                <p class="eticart-muted mb-0">E-ticaret entegrasyon paneli</p>
            </div>

            {{ $slot }}
        </div>
    </div>
</body>
</html>
