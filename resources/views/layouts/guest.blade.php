<!DOCTYPE html>
@php
    $uiTheme = \App\Support\UiTheme::fromRequest();
@endphp
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-bs-theme="{{ $uiTheme }}" style="color-scheme: {{ $uiTheme }}; background-color: {{ \App\Support\UiTheme::background($uiTheme) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate">
    <x-theme-boot />
    <title>{{ $appBrandName ?? config('app.name', 'EtiCart') }} — Giriş</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <x-production-assets />
</head>
<body>
    <div class="auth-wrap">
        <div class="auth-theme-toggle">
            <x-theme-toggle />
        </div>
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
    <script>
        (function () {
            const authPaths = /^\/(login|register|forgot-password|reset-password)(\/|$)/i;

            function isSafePanelUrl(value) {
                if (typeof value !== 'string' || value === '') {
                    return false;
                }
                try {
                    const target = new URL(value, window.location.origin);
                    if (target.origin !== window.location.origin) {
                        return false;
                    }
                    return !authPaths.test(target.pathname);
                } catch (error) {
                    return false;
                }
            }

            function restoreIfAuthenticated() {
                fetch(@json(route('session.status')), {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                }).then(function (response) {
                    return response.ok ? response.json() : null;
                }).then(function (data) {
                    if (!data || !data.authenticated) {
                        return;
                    }
                    const stored = sessionStorage.getItem('eticart.lastPanel');
                    const target = isSafePanelUrl(stored)
                        ? stored
                        : (isSafePanelUrl(data.last) ? data.last : @json(url('/dashboard')));
                    window.location.replace(target);
                }).catch(function () {});
            }

            restoreIfAuthenticated();
            window.addEventListener('pageshow', restoreIfAuthenticated);
            document.addEventListener('visibilitychange', function () {
                if (!document.hidden) {
                    restoreIfAuthenticated();
                }
            });
            setTimeout(restoreIfAuthenticated, 1500);
        })();
    </script>
</body>
</html>
