@extends('layouts.app')

@section('title', 'Ayarlar')

@section('content')
    <div class="mb-4">
        <h1 class="h3 mb-1">Ayarlar</h1>
        <p class="eticart-muted mb-0">Entegrasyon, raporlar ve sistem yapılandırması.</p>
    </div>

    <div class="row g-3">
        @php
            $cards = [
                ['route' => 'settings.general', 'title' => 'Genel', 'desc' => 'Sistem adı, firma bilgisi ve iade barkodu', 'icon' => 'bi-building', 'key' => 'general'],
                ['route' => 'settings.shopify', 'title' => 'Shopify', 'desc' => 'Mağaza URL ve access token', 'icon' => 'bi-shop', 'key' => 'shopify'],
                ['route' => 'settings.uyumsoft', 'title' => 'UyumSoft', 'desc' => 'API kullanıcı ve depo ayarları', 'icon' => 'bi-hdd-network', 'key' => 'uyumsoft'],
                ['route' => 'settings.cargo', 'title' => 'Kargo', 'desc' => 'Firma API bilgileri', 'icon' => 'bi-truck', 'key' => 'cargo'],
                ['route' => 'settings.mail', 'title' => 'Mail', 'desc' => 'SMTP ve gönderici bilgileri', 'icon' => 'bi-envelope', 'key' => 'mail'],
                ['route' => 'settings.sms', 'title' => 'SMS', 'desc' => 'Netgsm / provider ayarları', 'icon' => 'bi-phone', 'key' => 'sms'],
                ['route' => 'settings.sync', 'title' => 'Senkronizasyon', 'desc' => 'Zaman aralıkları ve otomasyon', 'icon' => 'bi-arrow-repeat', 'key' => 'sync'],
                ['route' => 'settings.templates.mail', 'title' => 'Mail Şablonları', 'desc' => 'E-posta içerik şablonları', 'icon' => 'bi-file-earmark-text', 'key' => 'mail'],
                ['route' => 'settings.templates.sms', 'title' => 'SMS Şablonları', 'desc' => 'SMS içerik şablonları', 'icon' => 'bi-chat-left-text', 'key' => 'sms'],
            ];

            $tools = [
                ['route' => 'reports.index', 'title' => 'Raporlar', 'desc' => 'Satış, kargo ve log raporları', 'icon' => 'bi-graph-up'],
            ];

            if (auth()->user()?->hasRole('admin')) {
                $tools[] = [
                    'route' => 'admin.queue.index',
                    'title' => 'Kuyruk (Queue)',
                    'desc' => 'Toplu ürün işlemleri ve başarısız arka plan işleri. Günlük kullanım için gerekli değil.',
                    'icon' => 'bi-list-task',
                ];
            }
        @endphp

        @foreach ($cards as $card)
            <div class="col-12 col-md-6 col-xl-3">
                <a href="{{ route($card['route']) }}" class="text-decoration-none text-body">
                    <div class="eticart-card p-3 h-100">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <i class="bi {{ $card['icon'] }} fs-4 text-secondary-brand"></i>
                            <span class="badge {{ !empty($status[$card['key']]) ? 'text-bg-success' : 'text-bg-warning' }}">
                                {{ !empty($status[$card['key']]) ? 'Hazır' : 'Eksik' }}
                            </span>
                        </div>
                        <h2 class="h5 mb-1">{{ $card['title'] }}</h2>
                        <p class="eticart-muted small mb-0">{{ $card['desc'] }}</p>
                    </div>
                </a>
            </div>
        @endforeach
    </div>

    <h2 class="h5 mt-4 mb-3">Sistem</h2>
    <div class="row g-3">
        @foreach ($tools as $card)
            <div class="col-12 col-md-6 col-xl-3">
                <a href="{{ route($card['route']) }}" class="text-decoration-none text-body">
                    <div class="eticart-card p-3 h-100">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <i class="bi {{ $card['icon'] }} fs-4 text-secondary-brand"></i>
                        </div>
                        <h2 class="h5 mb-1">{{ $card['title'] }}</h2>
                        <p class="eticart-muted small mb-0">{{ $card['desc'] }}</p>
                    </div>
                </a>
            </div>
        @endforeach
    </div>
@endsection
