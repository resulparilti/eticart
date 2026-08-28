import './bootstrap';
import * as bootstrap from 'bootstrap';
import Alpine from 'alpinejs';
import Chart from 'chart.js/auto';

window.Alpine = Alpine;
window.Chart = Chart;
window.bootstrap = bootstrap;

/**
 * Toast notification helper.
 *
 * @param {string} message
 * @param {'success'|'danger'|'warning'|'info'} type
 */
window.showToast = function showToast(message, type = 'success') {
    const container = document.getElementById('eticart-toast-container');
    if (!container) {
        return;
    }

    const toastEl = document.createElement('div');
    toastEl.className = `toast align-items-center text-bg-${type} border-0 show`;
    toastEl.setAttribute('role', 'alert');
    toastEl.setAttribute('aria-live', 'assertive');
    toastEl.setAttribute('aria-atomic', 'true');
    toastEl.innerHTML = `
        <div class="d-flex">
            <div class="toast-body">${message}</div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Kapat"></button>
        </div>
    `;
    container.appendChild(toastEl);

    setTimeout(() => {
        toastEl.remove();
    }, 4000);
};

/**
 * Confirm dialog helper.
 *
 * @param {string} message
 * @returns {boolean}
 */
window.confirmAction = function confirmAction(message) {
    return window.confirm(message);
};

/**
 * AJAX helper with CSRF support.
 *
 * @param {string} url
 * @param {object} options
 * @returns {Promise}
 */
window.ajaxRequest = async function ajaxRequest(url, options = {}) {
    const defaults = {
        method: 'GET',
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            Accept: 'application/json',
            'Content-Type': 'application/json',
        },
    };

    const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    if (token) {
        defaults.headers['X-CSRF-TOKEN'] = token;
    }

    const config = {
        ...defaults,
        ...options,
        headers: {
            ...defaults.headers,
            ...(options.headers || {}),
        },
    };

    const response = await fetch(url, config);
    const contentType = response.headers.get('content-type') || '';
    const payload = contentType.includes('application/json')
        ? await response.json()
        : await response.text();

    if (!response.ok) {
        const error = new Error('İstek başarısız oldu');
        error.status = response.status;
        error.payload = payload;
        throw error;
    }

    return payload;
};

/**
 * Basic required-field form validation.
 *
 * @param {HTMLFormElement} form
 * @returns {boolean}
 */
window.validateForm = function validateForm(form) {
    if (!form) {
        return false;
    }

    let isValid = true;
    form.querySelectorAll('[required]').forEach((field) => {
        if (!field.value || !String(field.value).trim()) {
            field.classList.add('is-invalid');
            isValid = false;
        } else {
            field.classList.remove('is-invalid');
        }
    });

    return isValid;
};

/**
 * Persistent sync / background job monitor (bottom-right FAB).
 */
window.eticartSyncMonitor = function eticartSyncMonitor() {
    const STORAGE_OPEN = 'eticart-sync-monitor-open';
    const STORAGE_SELECTED = 'eticart-sync-monitor-selected';

    return {
        open: false,
        loading: false,
        dismissing: false,
        activities: [],
        activeCount: 0,
        hasRecentError: false,
        selectedUuid: null,
        selected: null,
        selectedLogs: [],
        showScrollTop: false,
        pollTimer: null,
        refreshSeq: 0,
        liveUrl: '',
        showUrlTemplate: '',
        dismissUrlTemplate: '',
        cancelUrlTemplate: '',
        dismissFinishedUrl: '',
        historyUrl: '',

        init() {
            const root = document.getElementById('eticartSyncMonitor');
            this.liveUrl = root?.dataset.liveUrl || '';
            this.showUrlTemplate = root?.dataset.showUrlTemplate || '';
            this.dismissUrlTemplate = root?.dataset.dismissUrlTemplate || '';
            this.cancelUrlTemplate = root?.dataset.cancelUrlTemplate || '';
            this.dismissFinishedUrl = root?.dataset.dismissFinishedUrl || '';
            this.historyUrl = root?.dataset.historyUrl || '';
            const bootUuid = root?.dataset.bootUuid || '';

            try {
                this.open = localStorage.getItem(STORAGE_OPEN) === '1';
                this.selectedUuid = localStorage.getItem(STORAGE_SELECTED) || null;
            } catch (e) {
                // ignore
            }

            if (bootUuid) {
                this.selectedUuid = bootUuid;
                this.open = true;
                this.persist();
            }

            this.refresh();
            this.pollTimer = window.setInterval(() => this.refresh(), 2500);

            const onScroll = () => {
                this.showScrollTop = window.scrollY > 320;
            };
            onScroll();
            window.addEventListener('scroll', onScroll, { passive: true });

            document.addEventListener('visibilitychange', () => {
                if (!document.hidden) {
                    this.refresh();
                }
            });

            if (this.open && this.selectedUuid) {
                this.loadDetail(this.selectedUuid);
            }
        },

        scrollToTop() {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        },

        persist() {
            try {
                localStorage.setItem(STORAGE_OPEN, this.open ? '1' : '0');
                if (this.selectedUuid) {
                    localStorage.setItem(STORAGE_SELECTED, this.selectedUuid);
                } else {
                    localStorage.removeItem(STORAGE_SELECTED);
                }
            } catch (e) {
                // ignore
            }
        },

        toggle() {
            this.open = !this.open;
            this.persist();
            if (this.open) {
                this.refresh();
            }
        },

        clearSelection() {
            this.selectedUuid = null;
            this.selected = null;
            this.selectedLogs = [];
            this.persist();
        },

        historyDetailUrl(uuid) {
            if (!uuid || !this.historyUrl) return this.historyUrl || '#';
            return `${this.historyUrl.replace(/\/$/, '')}/${uuid}`;
        },

        statusLabel(status) {
            return ({
                queued: 'Bekliyor',
                running: 'Çalışıyor',
                completed: 'Tamam',
                partial: 'Kısmi',
                failed: 'Hata',
                cancelled: 'İptal',
            })[status] || status;
        },

        statusBadge(status) {
            return ({
                queued: 'text-bg-secondary',
                running: 'text-bg-info',
                completed: 'text-bg-success',
                partial: 'text-bg-warning',
                failed: 'text-bg-danger',
                cancelled: 'text-bg-secondary',
            })[status] || 'text-bg-light';
        },

        formatTime(iso) {
            if (!iso) return '';
            try {
                const d = new Date(iso);
                return d.toLocaleTimeString('tr-TR', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
            } catch (e) {
                return '';
            }
        },

        async select(uuid) {
            if (this.selectedUuid === uuid) {
                this.clearSelection();
                return;
            }
            this.selectedUuid = uuid;
            this.persist();
            await this.loadDetail(uuid);
        },

        async loadDetail(uuid) {
            if (!uuid || !this.showUrlTemplate) return;
            try {
                const url = this.showUrlTemplate.replace('__UUID__', uuid);
                const data = await window.ajaxRequest(url);
                if (this.selectedUuid !== uuid) return;
                this.selected = data;
                this.selectedLogs = data.logs || [];
                this.$nextTick(() => {
                    const el = this.$refs.logStream;
                    if (el) el.scrollTop = el.scrollHeight;
                });
            } catch (e) {
                // ignore transient errors
            }
        },

        async cancelOne(uuid) {
            if (!uuid || !this.cancelUrlTemplate || this.dismissing) return;
            if (!window.confirm('Bu işlem iptal edilsin mi? Takılı kalan aktarım da kapanır.')) {
                return;
            }
            this.dismissing = true;
            const seq = ++this.refreshSeq;
            try {
                const url = this.cancelUrlTemplate.replace('__UUID__', uuid);
                await window.ajaxRequest(url, { method: 'POST', body: JSON.stringify({}) });
                this.activities = this.activities.filter((a) => a.uuid !== uuid);
                if (this.selectedUuid === uuid) {
                    this.clearSelection();
                }
                if (window.showToast) {
                    window.showToast('İşlem iptal edildi.', 'success');
                }
                await this.refresh(seq);
            } catch (e) {
                if (window.showToast) {
                    window.showToast('İşlem iptal edilemedi.', 'danger');
                }
            } finally {
                this.dismissing = false;
            }
        },

        async dismissOne(uuid) {
            if (!uuid || !this.dismissUrlTemplate || this.dismissing) return;
            this.dismissing = true;
            const seq = ++this.refreshSeq;
            try {
                const url = this.dismissUrlTemplate.replace('__UUID__', uuid);
                await window.ajaxRequest(url, { method: 'POST', body: JSON.stringify({}) });
                this.activities = this.activities.filter((a) => a.uuid !== uuid);
                if (this.selectedUuid === uuid) {
                    this.clearSelection();
                }
                if (window.showToast) {
                    window.showToast('İşlem izleyiciden kaldırıldı.', 'success');
                }
                await this.refresh(seq);
            } catch (e) {
                if (window.showToast) {
                    window.showToast('İşlem kapatılamadı.', 'danger');
                }
            } finally {
                this.dismissing = false;
            }
        },

        async dismissSelected() {
            if (!this.selectedUuid) return;
            await this.dismissOne(this.selectedUuid);
        },

        async dismissFinished() {
            if (!this.dismissFinishedUrl || this.dismissing) return;
            this.dismissing = true;
            const seq = ++this.refreshSeq;
            try {
                await window.ajaxRequest(this.dismissFinishedUrl, { method: 'POST', body: JSON.stringify({}) });
                this.clearSelection();
                await this.refresh(seq);
                if (window.showToast) {
                    window.showToast('Bitmiş işlemler temizlendi.', 'success');
                }
            } catch (e) {
                if (window.showToast) {
                    window.showToast('Temizleme başarısız.', 'danger');
                }
            } finally {
                this.dismissing = false;
            }
        },

        async refresh(minSeq = null) {
            if (!this.liveUrl) return;
            const seq = minSeq ?? ++this.refreshSeq;
            this.loading = true;
            try {
                const data = await window.ajaxRequest(this.liveUrl);
                if (seq < this.refreshSeq) {
                    return;
                }
                this.activities = data.activities || [];
                this.activeCount = data.active_count || 0;
                this.hasRecentError = this.activities.some((a) => a.status === 'failed');
                if (data.history_url) {
                    this.historyUrl = data.history_url;
                }

                if (this.selectedUuid && !this.activities.some((a) => a.uuid === this.selectedUuid)) {
                    this.clearSelection();
                }

                if (this.open && this.selectedUuid) {
                    const current = this.activities.find((a) => a.uuid === this.selectedUuid);
                    if (current) {
                        this.selected = { ...(this.selected || {}), ...current };
                    }
                    if (this.activeCount > 0) {
                        await this.loadDetail(this.selectedUuid);
                    }
                }
            } catch (e) {
                // ignore
            } finally {
                if (seq >= this.refreshSeq) {
                    this.loading = false;
                }
            }
        },
    };
};

Alpine.data('eticartSyncMonitor', window.eticartSyncMonitor);

/**
 * Navbar global search — AJAX dropdown under input.
 */
window.eticartGlobalSearch = function eticartGlobalSearch() {
    return {
        q: '',
        loading: false,
        open: false,
        searched: false,
        groups: [],
        searchUrl: '',
        requestSeq: 0,

        init() {
            this.searchUrl = this.$el?.dataset.searchUrl || '';
        },

        close() {
            this.open = false;
        },

        async search() {
            const query = String(this.q || '').trim();
            if (query.length < 3) {
                this.groups = [];
                this.searched = false;
                this.open = false;
                return;
            }

            if (!this.searchUrl) return;

            const seq = ++this.requestSeq;
            this.loading = true;
            this.open = true;

            try {
                const url = `${this.searchUrl}?q=${encodeURIComponent(query)}`;
                const data = await window.ajaxRequest(url);
                if (seq !== this.requestSeq) return;
                this.groups = data.groups || [];
                this.searched = true;
                this.open = true;
            } catch (e) {
                if (seq !== this.requestSeq) return;
                this.groups = [];
                this.searched = true;
            } finally {
                if (seq === this.requestSeq) {
                    this.loading = false;
                }
            }
        },
    };
};

Alpine.data('eticartGlobalSearch', window.eticartGlobalSearch);

/**
 * Navbar digital clock synced to server timezone/time.
 */
window.eticartServerClock = function eticartServerClock() {
    return {
        dateText: '',
        timeText: '',
        offsetMs: 0,
        timezone: 'Europe/Istanbul',
        timer: null,

        init() {
            const el = this.$el;
            const serverTs = Number(el?.dataset.serverTs || Date.now());
            this.timezone = el?.dataset.timezone || 'Europe/Istanbul';
            this.offsetMs = serverTs - Date.now();
            this.tick();
            this.timer = window.setInterval(() => this.tick(), 1000);
        },

        tick() {
            const now = new Date(Date.now() + this.offsetMs);
            try {
                this.dateText = new Intl.DateTimeFormat('tr-TR', {
                    timeZone: this.timezone,
                    weekday: 'short',
                    day: '2-digit',
                    month: 'short',
                    year: 'numeric',
                }).format(now);

                this.timeText = new Intl.DateTimeFormat('tr-TR', {
                    timeZone: this.timezone,
                    hour: '2-digit',
                    minute: '2-digit',
                    second: '2-digit',
                    hour12: false,
                }).format(now);
            } catch (e) {
                this.dateText = now.toLocaleDateString('tr-TR');
                this.timeText = now.toLocaleTimeString('tr-TR', { hour12: false });
            }
        },
    };
};

Alpine.data('eticartServerClock', window.eticartServerClock);

/**
 * Lightweight rich text editor (contenteditable).
 */
window.eticartRichEditor = function eticartRichEditor(initialHtml = '') {
    return {
        html: initialHtml || '',

        init() {
            this.$nextTick(() => {
                if (this.$refs.editor) {
                    this.$refs.editor.innerHTML = this.html || '';
                }
            });
        },

        sync() {
            this.html = this.$refs.editor?.innerHTML || '';
        },

        cmd(command, value = null) {
            this.$refs.editor?.focus();
            document.execCommand(command, false, value);
            this.sync();
        },

        addLink() {
            const url = window.prompt('Bağlantı URL');
            if (!url) return;
            this.cmd('createLink', url);
        },
    };
};

Alpine.data('eticartRichEditor', window.eticartRichEditor);

/**
 * Kanban drag & drop.
 */
window.eticartKanban = function eticartKanban(config = {}) {
    return {
        moveUrl: config.moveUrl || '',
        dragCardId: null,
        dragFromColumn: null,

        init() {},

        onDragStart(event, cardId, columnId) {
            this.dragCardId = cardId;
            this.dragFromColumn = columnId;
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', String(cardId));
            event.currentTarget.classList.add('is-dragging');
        },

        onDragEnd() {
            document.querySelectorAll('.eticart-kanban__card.is-dragging').forEach((el) => {
                el.classList.remove('is-dragging');
            });
            this.dragCardId = null;
            this.dragFromColumn = null;
        },

        async onDrop(event, columnId) {
            const cardId = this.dragCardId || Number(event.dataTransfer.getData('text/plain'));
            if (!cardId || !this.moveUrl) return;

            const list = event.currentTarget.querySelector('[data-column-list]');
            const cardEl = document.querySelector(`[data-card-id="${cardId}"]`);
            if (list && cardEl) {
                list.appendChild(cardEl);
            }

            const orderedIds = Array.from(list?.querySelectorAll('[data-card-id]') || [])
                .map((el) => Number(el.getAttribute('data-card-id')))
                .filter(Boolean);

            try {
                await window.ajaxRequest(this.moveUrl, {
                    method: 'POST',
                    body: JSON.stringify({
                        card_id: cardId,
                        column_id: columnId,
                        ordered_ids: orderedIds,
                    }),
                });
            } catch (e) {
                if (window.showToast) {
                    window.showToast('Kart taşınamadı.', 'danger');
                }
            }
        },
    };
};

Alpine.data('eticartKanban', window.eticartKanban);

/**
 * Order calendar (month / week / day).
 */
window.eticartOrderCalendar = function eticartOrderCalendar(config = {}) {
    const pad = (n) => String(n).padStart(2, '0');
    const toYmd = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;

    return {
        eventsUrl: config.eventsUrl || '',
        viewMode: config.viewMode || 'month',
        focus: config.focusDate ? new Date(`${config.focusDate}T12:00:00`) : new Date(),
        events: [],
        loading: false,
        selected: null,
        weekdays: ['Pzt', 'Sal', 'Çar', 'Per', 'Cum', 'Cmt', 'Paz'],
        modal: null,

        get titleLabel() {
            return this.focus.toLocaleDateString('tr-TR', {
                year: 'numeric',
                month: 'long',
                ...(this.viewMode === 'day' ? { day: 'numeric' } : {}),
            });
        },

        get monthCells() {
            const year = this.focus.getFullYear();
            const month = this.focus.getMonth();
            const first = new Date(year, month, 1);
            const startOffset = (first.getDay() + 6) % 7; // Monday first
            const start = new Date(year, month, 1 - startOffset);
            const today = toYmd(new Date());
            const cells = [];
            for (let i = 0; i < 42; i += 1) {
                const d = new Date(start.getFullYear(), start.getMonth(), start.getDate() + i);
                const ymd = toYmd(d);
                cells.push({
                    key: ymd,
                    date: ymd,
                    day: d.getDate(),
                    inMonth: d.getMonth() === month,
                    isToday: ymd === today,
                });
            }
            return cells;
        },

        get listDays() {
            if (this.viewMode === 'day') {
                const ymd = toYmd(this.focus);
                return [{
                    date: ymd,
                    label: this.focus.toLocaleDateString('tr-TR', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }),
                }];
            }

            const day = (this.focus.getDay() + 6) % 7;
            const start = new Date(this.focus.getFullYear(), this.focus.getMonth(), this.focus.getDate() - day);
            const days = [];
            for (let i = 0; i < 7; i += 1) {
                const d = new Date(start.getFullYear(), start.getMonth(), start.getDate() + i);
                days.push({
                    date: toYmd(d),
                    label: d.toLocaleDateString('tr-TR', { weekday: 'long', day: 'numeric', month: 'long' }),
                });
            }
            return days;
        },

        init() {
            const modalEl = document.getElementById('calendarEventModal');
            if (modalEl && window.bootstrap?.Modal) {
                this.modal = window.bootstrap.Modal.getOrCreateInstance(modalEl);
            }
            this.load();
        },

        eventsFor(date) {
            return this.events.filter((e) => e.date === date);
        },

        setView(mode) {
            this.viewMode = mode;
            this.load();
        },

        goToday() {
            this.focus = new Date();
            this.load();
        },

        shift(dir) {
            const d = new Date(this.focus);
            if (this.viewMode === 'month') {
                d.setMonth(d.getMonth() + dir);
            } else if (this.viewMode === 'week') {
                d.setDate(d.getDate() + (7 * dir));
            } else {
                d.setDate(d.getDate() + dir);
            }
            this.focus = d;
            this.load();
        },

        range() {
            if (this.viewMode === 'month') {
                const cells = this.monthCells;
                return { from: cells[0].date, to: cells[cells.length - 1].date };
            }
            if (this.viewMode === 'week') {
                const days = this.listDays;
                return { from: days[0].date, to: days[days.length - 1].date };
            }
            const ymd = toYmd(this.focus);
            return { from: ymd, to: ymd };
        },

        async load() {
            if (!this.eventsUrl) return;
            this.loading = true;
            try {
                const { from, to } = this.range();
                const url = `${this.eventsUrl}?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`;
                const data = await window.ajaxRequest(url);
                this.events = data.events || [];
            } catch (e) {
                this.events = [];
            } finally {
                this.loading = false;
            }
        },

        openEvent(ev) {
            this.selected = ev;
            this.$nextTick(() => {
                const modalEl = document.getElementById('calendarEventModal');
                if (!modalEl) return;
                if (modalEl.parentElement !== document.body) {
                    document.body.appendChild(modalEl);
                }
                if (!this.modal && window.bootstrap?.Modal) {
                    this.modal = window.bootstrap.Modal.getOrCreateInstance(modalEl);
                }
                this.modal?.show();
            });
        },

        openDay(date) {
            this.focus = new Date(`${date}T12:00:00`);
            this.viewMode = 'day';
            this.load();
        },
    };
};

Alpine.data('eticartOrderCalendar', window.eticartOrderCalendar);

const ETICART_THEME_KEY = 'eticart-theme';

/**
 * @param {'dark'|'light'} theme
 */
window.applyEticartTheme = function applyEticartTheme(theme) {
    const next = theme === 'dark' ? 'dark' : 'light';
    const html = document.documentElement;
    html.setAttribute('data-bs-theme', next);
    html.style.colorScheme = next;
    html.style.backgroundColor = next === 'dark' ? '#0b1420' : '#f3f6f9';

    try {
        localStorage.setItem(ETICART_THEME_KEY, next);
        const secure = location.protocol === 'https:' ? '; Secure' : '';
        document.cookie = `${ETICART_THEME_KEY}=${next}; Path=/; Max-Age=31536000; SameSite=Lax${secure}`;
    } catch (e) {
        // ignore
    }

    document.querySelectorAll('[data-eticart-theme-toggle]').forEach((button) => {
        button.setAttribute('aria-label', next === 'dark' ? 'Açık temaya geç' : 'Koyu temaya geç');
        button.setAttribute('title', next === 'dark' ? 'Açık tema' : 'Koyu tema');
    });
};

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-eticart-theme-toggle]').forEach((button) => {
        button.addEventListener('click', () => {
            const current = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'dark' : 'light';
            window.applyEticartTheme(current === 'dark' ? 'light' : 'dark');
        });
    });

    const toggleBtn = document.getElementById('sidebarToggle');
    const sidebar = document.getElementById('eticartSidebar');
    const backdrop = document.getElementById('eticartSidebarBackdrop');

    const closeSidebar = () => {
        sidebar?.classList.remove('is-open');
        backdrop?.classList.remove('is-visible');
    };

    toggleBtn?.addEventListener('click', () => {
        sidebar?.classList.toggle('is-open');
        backdrop?.classList.toggle('is-visible');
    });

    backdrop?.addEventListener('click', closeSidebar);

    // Modal'ları body altına taşı (show öncesi): stacking context / backdrop üstte kalma sorunu.
    document.addEventListener('show.bs.modal', (event) => {
        const modal = event.target;
        if (modal instanceof HTMLElement && modal.classList.contains('modal') && modal.parentElement !== document.body) {
            document.body.appendChild(modal);
        }
    });

    // Sayfadaki tüm Bootstrap modallarını baştan body'ye al.
    document.querySelectorAll('.modal').forEach((modal) => {
        if (modal.parentElement !== document.body) {
            document.body.appendChild(modal);
        }
    });

    document.querySelectorAll('[data-confirm]').forEach((el) => {
        const handler = (event) => {
            const message = el.getAttribute('data-confirm') || 'Emin misiniz?';
            if (!window.confirmAction(message)) {
                event.preventDefault();
            }
        };

        if (el.tagName === 'FORM') {
            el.addEventListener('submit', handler);
        } else {
            el.addEventListener('click', handler);
        }
    });
});

window.productMedia = function productMedia(gallery = []) {
    return {
        showThumbs: false,
        lightboxIndex: -1,
        gallery: Array.isArray(gallery) ? gallery.filter((url) => typeof url === 'string' && url.trim() !== '') : [],
        get lightbox() {
            if (this.lightboxIndex < 0) {
                return null;
            }

            return this.gallery[this.lightboxIndex] || null;
        },
        get lightboxCount() {
            return this.gallery.length;
        },
        openLightbox(url) {
            const src = typeof url === 'string' ? url.trim() : '';
            if (src === '') {
                return;
            }
            let index = this.gallery.indexOf(src);
            if (index < 0) {
                this.gallery.push(src);
                index = this.gallery.length - 1;
            }
            this.lightboxIndex = index;
            document.body.style.overflow = 'hidden';
        },
        closeLightbox() {
            this.lightboxIndex = -1;
            document.body.style.overflow = '';
        },
        prevLightbox() {
            if (this.lightboxIndex < 0 || this.gallery.length < 2) {
                return;
            }
            this.lightboxIndex = (this.lightboxIndex - 1 + this.gallery.length) % this.gallery.length;
        },
        nextLightbox() {
            if (this.lightboxIndex < 0 || this.gallery.length < 2) {
                return;
            }
            this.lightboxIndex = (this.lightboxIndex + 1) % this.gallery.length;
        },
        scrollThumbs(direction) {
            const track = this.$refs.thumbTrack;
            if (!track) {
                return;
            }
            const step = Math.max(88, Math.floor(track.clientWidth * 0.7));
            track.scrollBy({ left: direction * step, behavior: 'smooth' });
        },
    };
};

Alpine.data('productMedia', window.productMedia);

Alpine.start();
