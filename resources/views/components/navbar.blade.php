<nav class="eticart-navbar d-flex align-items-center px-3 px-lg-4 no-print">
    <button type="button" id="sidebarToggle" class="btn btn-link text-decoration-none d-lg-none me-2 p-0" aria-label="Menüyü aç">
        <i class="bi bi-list fs-3"></i>
    </button>

    <div
        class="eticart-global-search d-none d-md-block flex-grow-1 me-3"
        data-search-url="{{ route('search.global') }}"
        x-data="eticartGlobalSearch"
        x-init="init()"
        @click.outside="close()"
    >
        <div class="eticart-global-search__field input-group">
            <span class="input-group-text bg-transparent"><i class="bi bi-search"></i></span>
            <input
                type="search"
                x-model="q"
                @input.debounce.300ms="search()"
                @keydown.escape.prevent="close()"
                @focus="open = q.trim().length >= 3"
                class="form-control border-start-0"
                placeholder="Sipariş, ürün, müşteri veya telefon ara... (min. 3)"
                aria-label="Hızlı arama"
                autocomplete="off"
            >
            <span class="input-group-text bg-transparent eticart-global-search__spinner" x-show="loading" x-cloak>
                <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
            </span>
        </div>

        <div class="eticart-global-search__dropdown" x-show="open" x-cloak>
            <template x-if="q.trim().length < 3">
                <div class="eticart-global-search__hint">En az 3 karakter girin.</div>
            </template>

            <template x-if="q.trim().length >= 3 && !loading && groups.length === 0 && searched">
                <div class="eticart-global-search__hint">Sonuç bulunamadı.</div>
            </template>

            <template x-for="group in groups" :key="group.key">
                <div class="eticart-global-search__group">
                    <div class="eticart-global-search__group-label" x-text="group.label"></div>
                    <template x-for="item in group.items" :key="item.url">
                        <a :href="item.url" class="eticart-global-search__item">
                            <div class="fw-semibold text-truncate" x-text="item.title"></div>
                            <div class="small eticart-muted text-truncate" x-show="item.subtitle" x-text="item.subtitle"></div>
                        </a>
                    </template>
                </div>
            </template>
        </div>
    </div>

    <div class="ms-auto d-flex align-items-center gap-2 gap-lg-3">
        <div
            class="eticart-server-clock"
            data-server-ts="{{ (int) round(microtime(true) * 1000) }}"
            data-timezone="{{ config('app.timezone') }}"
            x-data="eticartServerClock"
            x-init="init()"
            title="Sunucu saati (Europe/Istanbul)"
        >
            <div class="eticart-server-clock__date" x-text="dateText"></div>
            <div class="eticart-server-clock__time" x-text="timeText"></div>
        </div>

        @php
            $alertUnread = $adminNotificationUnread ?? 0;
            $alertItems = $adminNotificationLatest ?? collect();
            $todoPending = $todoPendingCount ?? 0;
        @endphp

        <a href="{{ route('todos.index') }}" class="btn btn-sm btn-outline-secondary position-relative" title="Yapılacaklar" aria-label="Yapılacaklar">
            <i class="bi bi-check2-square"></i>
            @if ($todoPending > 0)
                <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size: 0.55rem;">
                    {{ $todoPending > 99 ? '99+' : $todoPending }}
                </span>
            @endif
        </a>

        <div class="dropdown">
            <button type="button" class="btn btn-sm btn-outline-secondary position-relative" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Bildirimler" title="Bildirimler">
                <i class="bi bi-bell"></i>
                @if ($alertUnread > 0)
                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size: 0.55rem;">
                        {{ $alertUnread > 99 ? '99+' : $alertUnread }}
                    </span>
                @endif
            </button>
            <div class="dropdown-menu dropdown-menu-end p-0" style="min-width: 340px; max-width: 420px;">
                <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom">
                    <strong class="small">Bildirimler</strong>
                    <a href="{{ route('alerts.index') }}" class="small">Tümü</a>
                </div>
                @forelse ($alertItems as $item)
                    <a href="{{ route('alerts.read', $item) }}" class="dropdown-item py-2 {{ $item->isUnread() ? 'bg-light' : '' }}">
                        <div class="d-flex gap-2">
                            <i class="bi {{ $item->icon() }} mt-1"></i>
                            <div>
                                <div class="small fw-semibold">{{ $item->title }}</div>
                                <div class="small eticart-muted">{{ optional($item->created_at)->diffForHumans() }}</div>
                            </div>
                        </div>
                    </a>
                @empty
                    <div class="px-3 py-3 small eticart-muted">Yeni bildirim yok.</div>
                @endforelse
                <div class="border-top px-3 py-2">
                    <a href="{{ route('alerts.index') }}" class="small">Geçmiş bildirimler</a>
                </div>
            </div>
        </div>

        <div class="dropdown">
            <button class="btn btn-sm btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="bi bi-person-circle me-1"></i>
                <span class="d-none d-sm-inline">{{ Auth::user()->name ?? 'Kullanıcı' }}</span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li>
                    <a class="dropdown-item" href="{{ route('profile.edit') }}">
                        <i class="bi bi-gear me-2"></i> Profil
                    </a>
                </li>
                <li><hr class="dropdown-divider"></li>
                <li>
                    <a class="dropdown-item" href="{{ route('notes.index') }}">
                        <i class="bi bi-journal-text me-2"></i> Notlar
                    </a>
                </li>
                <li>
                    <a class="dropdown-item" href="{{ route('kanban.index') }}">
                        <i class="bi bi-kanban me-2"></i> Kanban
                    </a>
                </li>
                <li>
                    <a class="dropdown-item" href="{{ route('calendar.index') }}">
                        <i class="bi bi-calendar3 me-2"></i> Takvim
                    </a>
                </li>
                <li><hr class="dropdown-divider"></li>
                <li>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="dropdown-item text-danger">
                            <i class="bi bi-box-arrow-right me-2"></i> Çıkış
                        </button>
                    </form>
                </li>
            </ul>
        </div>
    </div>
</nav>
