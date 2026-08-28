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
    <title>@yield('title', $title ?? 'Anasayfa') — {{ $appBrandName ?? config('app.name', 'EtiCart') }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <x-production-assets />
    @stack('styles')
</head>
<body>
    <div class="eticart-shell">
        <div id="eticartSidebarBackdrop" class="eticart-sidebar-backdrop"></div>

        <x-sidebar />

        <div class="eticart-main">
            <x-navbar />

            <main class="eticart-content">
                <x-alert />

                @isset($breadcrumbs)
                    <x-breadcrumb :items="$breadcrumbs" />
                @endisset

                @hasSection('content')
                    @yield('content')
                @else
                    {{ $slot ?? '' }}
                @endif
            </main>

            <footer class="border-top py-3 px-4 eticart-muted small no-print">
                <div class="d-flex justify-content-between flex-wrap gap-2 align-items-center">
                    <span>&copy; {{ date('Y') }} {{ $appBrandName ?? config('app.name', 'EtiCart') }}</span>
                    <span class="d-flex flex-wrap gap-3 align-items-center">
                        <span title="Yurtiçi Kargo ve dış API çağrılarında kullanılan sunucu çıkış IP adresi">
                            Çıkış IP:
                            @if (filled($serverOutboundIp ?? null))
                                <code class="user-select-all">{{ $serverOutboundIp }}</code>
                            @else
                                <span class="text-muted">alınamadı</span>
                            @endif
                        </span>
                    </span>
                </div>
            </footer>
        </div>
    </div>

    <div id="eticart-toast-container" class="eticart-toast-container"></div>

    @if (\App\Support\PermissionCatalog::allows(auth()->user(), 'sync.view'))
    <x-sync-monitor />
    @endif

    @stack('scripts')
    <script>
        (function () {
            try {
                sessionStorage.setItem('eticart.lastPanel', window.location.href);
            } catch (error) {}
        })();
    </script>
</body>
</html>
