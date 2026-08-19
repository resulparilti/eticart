@php
    $counts = $sidebarCounts ?? ['open_orders' => 0, 'pending_shipments' => 0, 'unread_alerts' => 0];
    $isAdmin = false;
    try {
        $isAdmin = auth()->user()?->hasRole('admin') ?? false;
    } catch (\Throwable) {
        $isAdmin = false;
    }

    $menu = [
        ['route' => 'dashboard', 'label' => 'Anasayfa', 'icon' => 'bi-speedometer2'],
        [
            'route' => 'orders.index',
            'label' => 'Siparişler',
            'icon' => 'bi-bag-check',
            'badge' => (int) ($counts['open_orders'] ?? 0),
        ],
        ['route' => 'products.index', 'label' => 'Ürünler', 'icon' => 'bi-box-seam'],
        ['route' => 'customers.index', 'label' => 'Müşteriler', 'icon' => 'bi-people'],
        [
            'route' => 'shipments.index',
            'label' => 'Kargolar',
            'icon' => 'bi-truck',
            'badge' => (int) ($counts['pending_shipments'] ?? 0),
        ],
        ['route' => 'invoices.index', 'label' => 'Faturalar', 'icon' => 'bi-receipt'],
        [
            'route' => 'alerts.index',
            'label' => 'Bildirimler',
            'icon' => 'bi-bell',
            'badge' => (int) ($counts['unread_alerts'] ?? 0),
        ],
        ['route' => 'notifications.index', 'label' => 'Mesaj bilgilendirmeleri', 'icon' => 'bi-envelope-paper'],
    ];

    if ($isAdmin) {
        $menu[] = ['route' => 'users.index', 'label' => 'Kullanıcılar', 'icon' => 'bi-person-gear'];
    }

    $menu[] = ['route' => 'sync-history.index', 'label' => 'İşlem geçmişi', 'icon' => 'bi-clock-history'];
    $menu[] = ['route' => 'settings.index', 'label' => 'Ayarlar', 'icon' => 'bi-sliders'];
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

    <nav class="eticart-sidebar__nav" aria-label="Ana menü">
        @foreach ($menu as $item)
            @php
                $hasRoute = Route::has($item['route']);
                $url = $hasRoute ? route($item['route']) : '#';
                $isActive = $hasRoute && (
                    request()->routeIs($item['route'])
                    || request()->routeIs(str_replace('.index', '.*', $item['route']))
                    || request()->routeIs($item['route'] . '.*')
                );
                $badge = (int) ($item['badge'] ?? 0);
            @endphp
            <a href="{{ $url }}" class="eticart-nav-link {{ $isActive ? 'is-active' : '' }}" @if(!$hasRoute) aria-disabled="true" title="Yakında" @endif>
                <i class="bi {{ $item['icon'] }}"></i>
                <span class="eticart-nav-link__label">{{ $item['label'] }}</span>
                @if ($badge > 0)
                    <span class="eticart-nav-badge" title="{{ $item['label'] }}">{{ $badge > 99 ? '99+' : $badge }}</span>
                @endif
            </a>
        @endforeach
    </nav>

    <div class="eticart-sidebar__footer">
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="eticart-nav-link is-logout">
                <i class="bi bi-box-arrow-right"></i>
                <span class="eticart-nav-link__label">Çıkış yap</span>
            </button>
        </form>
    </div>
</aside>
