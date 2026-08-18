<div
    id="eticartSyncMonitor"
    class="eticart-sync-monitor no-print"
    data-live-url="{{ route('sync-activities.live') }}"
    data-show-url-template="{{ url('/sync-activities') }}/__UUID__"
    data-dismiss-url-template="{{ url('/sync-activities') }}/__UUID__/dismiss"
    data-dismiss-finished-url="{{ route('sync-activities.dismiss-finished') }}"
    data-history-url="{{ route('sync-history.index') }}"
    data-boot-uuid="{{ session('sync_activity_uuid') }}"
    x-data="eticartSyncMonitor"
    x-init="init()"
>
    <div class="eticart-sync-fab-stack">
        <button
            type="button"
            class="eticart-scroll-top"
            x-show="showScrollTop"
            x-cloak
            @click="scrollToTop()"
            title="Yukarı çık"
            aria-label="Sayfanın en üstüne git"
        >
            <i class="bi bi-chevron-up"></i>
        </button>

        <button
            type="button"
            class="eticart-sync-fab"
            @click="toggle()"
            :class="{ 'is-active': open, 'has-running': activeCount > 0, 'has-error': hasRecentError && activeCount === 0 }"
            :title="open ? 'İşlem panelini kapat' : 'İşlem izleyici'"
            aria-label="İşlem izleyici"
        >
            <span class="eticart-sync-fab__icon">
                <i class="bi" :class="activeCount > 0 ? 'bi-arrow-repeat eticart-spin' : 'bi-terminal'"></i>
            </span>
            <span class="eticart-sync-fab__badge" x-show="activeCount > 0" x-cloak x-text="activeCount"></span>
        </button>
    </div>

    <div class="eticart-sync-panel eticart-card" x-show="open" x-cloak>
        <div class="eticart-sync-panel__head">
            <div>
                <div class="fw-semibold">İşlem izleyici</div>
                <div class="small eticart-muted">Detay için kayda tıklayın</div>
            </div>
            <div class="d-flex gap-1">
                <button
                    type="button"
                    class="btn btn-sm btn-outline-secondary"
                    @click="dismissFinished()"
                    x-show="activities.some(a => a.can_dismiss)"
                    x-cloak
                    :disabled="dismissing"
                    title="Bitmişleri temizle"
                >
                    <i class="bi bi-check2-all"></i>
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary" @click="refresh()" :disabled="loading" title="Yenile">
                    <i class="bi bi-arrow-clockwise"></i>
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary" @click="open = false; persist()" title="Kapat">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
        </div>

        <div class="eticart-sync-panel__list">
            <template x-if="activities.length === 0">
                <div class="p-3 small eticart-muted text-center">Şu an izlenen işlem yok.</div>
            </template>
            <template x-for="item in activities" :key="item.uuid">
                <div
                    class="eticart-sync-item"
                    :class="{ 'is-selected': selectedUuid === item.uuid }"
                >
                    <button type="button" class="eticart-sync-item__main" @click="select(item.uuid)">
                        <div class="d-flex justify-content-between gap-2">
                            <span class="fw-semibold text-truncate" x-text="item.title"></span>
                            <span class="badge" :class="statusBadge(item.status)" x-text="statusLabel(item.status)"></span>
                        </div>
                        <div class="small eticart-muted text-truncate" x-text="item.message || '—'"></div>
                        <div class="progress mt-2" style="height: 6px;" x-show="item.progress_percent !== null || item.is_active">
                            <div
                                class="progress-bar"
                                :class="item.is_active ? 'progress-bar-striped progress-bar-animated' : ''"
                                role="progressbar"
                                :style="`width: ${item.progress_percent ?? (item.is_active ? 15 : 100)}%`"
                            ></div>
                        </div>
                        <div class="small eticart-muted mt-1" x-show="item.progress_total">
                            <span x-text="item.progress_current"></span>/<span x-text="item.progress_total"></span>
                            <span x-show="item.progress_percent !== null"> (<span x-text="item.progress_percent"></span>%)</span>
                        </div>
                    </button>
                    <button
                        type="button"
                        class="eticart-sync-item__dismiss"
                        x-show="item.can_dismiss"
                        x-cloak
                        @click.stop="dismissOne(item.uuid)"
                        :disabled="dismissing"
                        title="İzleyiciden kaldır"
                        aria-label="Tamam"
                    >
                        <i class="bi bi-check-circle-fill"></i>
                    </button>
                </div>
            </template>
        </div>

        <div class="eticart-sync-panel__logs" x-show="selectedUuid && selected" x-cloak>
            <div class="eticart-sync-panel__logs-head">
                <span class="small fw-semibold">Detay</span>
                <button type="button" class="btn btn-sm btn-link p-0 text-decoration-none" @click="clearSelection()" title="Detayı kapat">
                    Kapat
                </button>
            </div>
            <div class="px-3 py-2 border-bottom">
                <div class="fw-semibold small text-truncate" x-text="selected?.title || ''"></div>
                <div class="small eticart-muted text-truncate" x-text="selected?.message || ''"></div>
            </div>
            <div class="eticart-sync-log-stream" x-ref="logStream">
                <template x-if="!selectedLogs.length">
                    <div class="small eticart-muted p-2">Log yok.</div>
                </template>
                <template x-for="log in selectedLogs" :key="log.id">
                    <div class="eticart-sync-log" :class="`is-${log.level}`">
                        <span class="eticart-sync-log__time" x-text="formatTime(log.created_at)"></span>
                        <span class="eticart-sync-log__level" x-text="log.level"></span>
                        <span class="eticart-sync-log__msg" x-text="log.message"></span>
                    </div>
                </template>
            </div>
        </div>

        <div class="eticart-sync-panel__foot">
            <a :href="historyUrl" class="small text-decoration-none">
                Tüm işlem geçmişi <i class="bi bi-arrow-right"></i>
            </a>
        </div>
    </div>
</div>
