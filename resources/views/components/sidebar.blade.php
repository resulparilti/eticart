@php
    $menu = [
        ['route' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'bi-speedometer2'],
        ['route' => 'orders.index', 'label' => 'Siparişler', 'icon' => 'bi-bag-check', 'fallback' => '#'],
        ['route' => 'customers.index', 'label' => 'Müşteriler', 'icon' => 'bi-people', 'fallback' => '#'],
        ['route' => 'products.index', 'label' => 'Ürünler', 'icon' => 'bi-box-seam', 'fallback' => '#'],
        ['route' => 'shipments.index', 'label' => 'Kargo', 'icon' => 'bi-truck', 'fallback' => '#'],
        ['route' => 'notifications.index', 'label' => 'Bilgilendirmeler', 'icon' => 'bi-envelope-paper', 'fallback' => '#'],
        ['route' => 'alerts.index', 'label' => 'Bildirimler', 'icon' => 'bi-bell', 'fallback' => '#'],
        ['route' => 'settings.index', 'label' => 'Ayarlar', 'icon' => 'bi-sliders', 'fallback' => '#'],
        ['route' => 'settings.sync', 'label' => 'Senkronizasyon', 'icon' => 'bi-arrow-repeat', 'fallback' => '#'],
        ['route' => 'users.index', 'label' => 'Kullanıcılar', 'icon' => 'bi-person-gear', 'fallback' => '#'],
        ['route' => 'reports.index', 'label' => 'Raporlar', 'icon' => 'bi-graph-up', 'fallback' => '#'],
        ['route' => 'sync-history.index', 'label' => 'İşlem Geçmişi', 'icon' => 'bi-clock-history', 'fallback' => '#'],
        ['route' => 'admin.queue.index', 'label' => 'Queue', 'icon' => 'bi-list-task', 'fallback' => '#'],
    ];
@endphp

<aside id="eticartSidebar" class="eticart-sidebar no-print">
    <a href="{{ route('dashboard') }}" class="eticart-sidebar__brand">
        @php
            $brand = $appBrandName ?? \App\Models\Setting::appName();
        @endphp
        @if (preg_match('/^(Eti)(Cart)$/iu', $brand, $parts) === 1)
            {{ $parts[1] }}<span>{{ $parts[2] }}</span>
        @else
            {{ $brand }}
        @endif
    </a>

    <nav class="py-3" aria-label="Ana menü">
        @foreach ($menu as $item)
            @php
                $hasRoute = Route::has($item['route']);
                $url = $hasRoute ? route($item['route']) : ($item['fallback'] ?? '#');
                $isActive = $hasRoute && (
                    request()->routeIs($item['route'])
                    || request()->routeIs(str_replace('.index', '.*', $item['route']))
                    || request()->routeIs($item['route'] . '.*')
                );
            @endphp
            <a href="{{ $url }}" class="eticart-nav-link {{ $isActive ? 'is-active' : '' }}" @if(!$hasRoute) aria-disabled="true" title="Yakında" @endif>
                <i class="bi {{ $item['icon'] }}"></i>
                <span>{{ $item['label'] }}</span>
            </a>
        @endforeach
    </nav>
</aside>
