@extends('layouts.app')

@section('title', 'Takvim')

@section('content')
    <div
        class="eticart-calendar"
        x-data="eticartOrderCalendar(@js([
            'eventsUrl' => $eventsUrl,
            'focusDate' => $focusDate,
            'viewMode' => $viewMode,
        ]))"
        x-init="init()"
    >
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            <div>
                <h1 class="h3 mb-1">Sipariş takvimi</h1>
                <p class="eticart-muted mb-0">Siparişler durumuna göre renklendirilir.</p>
            </div>
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <div class="btn-group">
                    <button type="button" class="btn btn-sm" :class="viewMode === 'month' ? 'btn-primary' : 'btn-outline-secondary'" @click="setView('month')">Ay</button>
                    <button type="button" class="btn btn-sm" :class="viewMode === 'week' ? 'btn-primary' : 'btn-outline-secondary'" @click="setView('week')">Hafta</button>
                    <button type="button" class="btn btn-sm" :class="viewMode === 'day' ? 'btn-primary' : 'btn-outline-secondary'" @click="setView('day')">Gün</button>
                </div>
                <div class="btn-group">
                    <button type="button" class="btn btn-sm btn-outline-secondary" @click="shift(-1)"><i class="bi bi-chevron-left"></i></button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" @click="goToday()">Bugün</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" @click="shift(1)"><i class="bi bi-chevron-right"></i></button>
                </div>
            </div>
        </div>

        <div class="eticart-card p-3 mb-3">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <strong x-text="titleLabel"></strong>
                <div class="small eticart-muted" x-show="loading">Yükleniyor…</div>
            </div>
        </div>

        <div class="eticart-calendar__legend small mb-3 d-flex flex-wrap gap-3">
            <span><i class="bi bi-circle-fill" style="color:#6c757d"></i> Karşılanmadı</span>
            <span><i class="bi bi-circle-fill" style="color:#0dcaf0"></i> Hazırlanıyor</span>
            <span><i class="bi bi-circle-fill" style="color:#ffc107"></i> Kısmi</span>
            <span><i class="bi bi-circle-fill" style="color:#198754"></i> Kargoya verildi</span>
            <span><i class="bi bi-circle-fill" style="color:#dc3545"></i> İptal</span>
        </div>

        <div class="eticart-card overflow-hidden">
            <template x-if="viewMode === 'month'">
                <div>
                    <div class="eticart-calendar__weekdays">
                        <template x-for="d in weekdays" :key="d">
                            <div x-text="d"></div>
                        </template>
                    </div>
                    <div class="eticart-calendar__month">
                        <template x-for="cell in monthCells" :key="cell.key">
                            <div class="eticart-calendar__day" :class="{ 'is-muted': !cell.inMonth, 'is-today': cell.isToday }">
                                <div class="eticart-calendar__day-num" x-text="cell.day"></div>
                                <div class="eticart-calendar__events">
                                    <template x-for="ev in eventsFor(cell.date).slice(0, 3)" :key="ev.id">
                                        <button type="button" class="eticart-calendar__event" :style="`background:${ev.color}`" @click="openEvent(ev)" x-text="ev.title"></button>
                                    </template>
                                    <button
                                        type="button"
                                        class="eticart-calendar__more"
                                        x-show="eventsFor(cell.date).length > 3"
                                        @click="openDay(cell.date)"
                                        x-text="`+${eventsFor(cell.date).length - 3} daha`"
                                    ></button>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </template>

            <template x-if="viewMode === 'week' || viewMode === 'day'">
                <div class="eticart-calendar__list p-3">
                    <template x-for="day in listDays" :key="day.date">
                        <div class="mb-4">
                            <div class="fw-semibold mb-2" x-text="day.label"></div>
                            <template x-if="eventsFor(day.date).length === 0">
                                <div class="small eticart-muted">Sipariş yok.</div>
                            </template>
                            <div class="d-flex flex-column gap-2">
                                <template x-for="ev in eventsFor(day.date)" :key="ev.id">
                                    <button type="button" class="eticart-calendar__list-item" @click="openEvent(ev)">
                                        <span class="eticart-calendar__dot" :style="`background:${ev.color}`"></span>
                                        <span class="fw-semibold text-truncate" x-text="ev.title"></span>
                                        <span class="small eticart-muted ms-auto" x-text="ev.status_label"></span>
                                    </button>
                                </template>
                            </div>
                        </div>
                    </template>
                </div>
            </template>
        </div>

        <div class="modal fade" id="calendarEventModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" x-text="selected?.title || 'Sipariş'"></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <dl class="row mb-0 small">
                            <dt class="col-4">Sipariş</dt>
                            <dd class="col-8" x-text="'#' + (selected?.order_number || '')"></dd>
                            <dt class="col-4">Alıcı</dt>
                            <dd class="col-8">
                                <div x-text="selected?.customer_name || '—'"></div>
                                <div class="eticart-muted" x-text="selected?.customer_phone || ''"></div>
                                <div class="eticart-muted" x-text="selected?.customer_email || ''"></div>
                            </dd>
                            <dt class="col-4">İçerik</dt>
                            <dd class="col-8" x-text="selected?.items_preview || '—'"></dd>
                            <dt class="col-4">Tutar</dt>
                            <dd class="col-8" x-text="selected?.total || '—'"></dd>
                            <dt class="col-4">Durum</dt>
                            <dd class="col-8">
                                <span class="badge text-white" :style="`background:${selected?.color || '#6c757d'}`" x-text="selected?.status_label"></span>
                            </dd>
                        </dl>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Kapat</button>
                        <a :href="selected?.url || '#'" class="btn btn-primary">Sipariş detayına git</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
