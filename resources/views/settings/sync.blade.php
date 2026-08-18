@extends('layouts.app')

@section('title', 'Senkronizasyon Ayarları')

@section('content')
    @php
        $cronMin = $cronMin ?? \App\Support\SyncIntervalOptions::minCronMinutes();
        $intervals = $intervals ?? \App\Support\SyncIntervalOptions::all();
        $isVps = ($deploymentMode ?? 'vps') === 'vps';
    @endphp

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">Senkronizasyon</h1>
            <p class="eticart-muted mb-0">
                {{ $isVps ? 'VPS sunucu için sık tarama aralıkları.' : 'Paylaşımlı hosting için güvenli aralıklar.' }}
            </p>
        </div>
        <a href="{{ route('settings.index') }}" class="btn btn-outline-secondary">Geri</a>
    </div>

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="eticart-card p-3">
                <form method="POST" action="{{ route('settings.sync.update') }}">
                    @csrf
                    @method('PUT')

                    <div class="mb-3">
                        <label class="form-label">Sipariş kontrol aralığı (dk)</label>
                        <select name="sync_orders_interval" class="form-select" required>
                            @foreach ($intervals['orders'] ?? [5, 15] as $min)
                                <option value="{{ $min }}" @selected((int) old('sync_orders_interval', $settings['sync_orders_interval'] ?? ($isVps ? 5 : 15)) === $min)>{{ $min }}</option>
                            @endforeach
                        </select>
                        <div class="form-text">Shopify sipariş taraması ve UyumSoft sipariş gönderimi aynı aralıkta çalışır.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Ürün senkronizasyonu (dk)</label>
                        <select name="sync_products_interval" class="form-select" required>
                            @foreach ($intervals['products'] ?? [15, 30] as $min)
                                <option value="{{ $min }}" @selected((int) old('sync_products_interval', $settings['sync_products_interval'] ?? ($isVps ? 15 : 30)) === $min)>{{ $min }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Stok güncellemesi (dk)</label>
                        <select name="sync_stock_interval" class="form-select" required>
                            @foreach ($intervals['stock'] ?? [5, 15] as $min)
                                <option value="{{ $min }}" @selected((int) old('sync_stock_interval', $settings['sync_stock_interval'] ?? ($isVps ? 5 : 15)) === $min)>{{ $min }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Kargo durumu kontrol (dk)</label>
                        <select name="sync_cargo_interval" class="form-select" required>
                            @foreach ($intervals['cargo'] ?? [15, 30] as $min)
                                <option value="{{ $min }}" @selected((int) old('sync_cargo_interval', $settings['sync_cargo_interval'] ?? 15) === $min)>{{ $min }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="auto_create_shipment" value="1" id="auto_create_shipment" @checked(($settings['auto_create_shipment'] ?? '0') === '1')>
                        <label class="form-check-label" for="auto_create_shipment">Otomatik kargo oluştur</label>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="auto_send_tracking" value="1" id="auto_send_tracking" @checked(($settings['auto_send_tracking'] ?? '0') === '1')>
                        <label class="form-check-label" for="auto_send_tracking">Otomatik tracking gönder</label>
                    </div>

                    <button type="submit" class="btn btn-primary">Kaydet</button>
                </form>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="eticart-card p-3 mb-3">
                <h2 class="h6 mb-2">Nasıl çalışır?</h2>
                <p class="small eticart-muted mb-2">
                    Sunucuda cron ile <code>php artisan schedule:run</code> her dakika tetiklenmelidir (VPS).
                    Aynı turda kuyruk işleri de işlenir; ayrı worker gerekmez.
                    Her tarama <strong>İşlem Geçmişi</strong> ve senkron loglarına yazılır.
                </p>
                <p class="small eticart-muted mb-2">
                    Shopify siparişleri önce panele alınır, ardından UyumSoft’a satış olarak işlenir.
                    UyumSoft’ta fatura kesilmişse PDF de siparişe bağlanır.
                </p>
                <p class="small eticart-muted mb-0">
                    Yurtiçi Kargo standart SOAP API’sinde webhook yoktur; açık kargolar belirlenen aralıkla sorgulanır.
                </p>
            </div>
            <div class="eticart-card p-3">
                <h2 class="h6 mb-3">Aktif Sync Job'ları</h2>
                <ul class="list-group list-group-flush">
                    @forelse ($jobs as $job)
                        <li class="list-group-item px-0 bg-transparent d-flex justify-content-between">
                            <span>{{ $job->job_type }}</span>
                            <span class="eticart-muted">{{ $job->interval_minutes }} dk / {{ $job->status }}</span>
                        </li>
                    @empty
                        <li class="list-group-item px-0 bg-transparent">Kayıt yok</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>

    <div class="mt-4">
        <div class="accordion" id="syncHostingAccordion">
            <div class="accordion-item eticart-card border-0">
                <h2 class="accordion-header">
                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#syncHostingHelp">
                        Paylaşımlı hosting / cron bilgisi
                    </button>
                </h2>
                <div id="syncHostingHelp" class="accordion-collapse collapse" data-bs-parent="#syncHostingAccordion">
                    <div class="accordion-body">
                        <div class="alert alert-info small mb-3">
                            Paylaşımlı hostingte cron genelde en az <strong>15 dakika</strong> destekler.
                            Projeyi oraya taşırsanız <code>ETICART_DEPLOYMENT=shared</code> ve
                            <code>SCHEDULE_CRON_MINUTES=15</code> kullanın.
                        </div>
                        <h3 class="h6">Cron durumu</h3>
                        @if ($cronHeartbeat ?? null)
                            @php $mins = $cronHeartbeat->diffInMinutes(now()); @endphp
                            <p class="small mb-2 {{ $mins <= ($cronMin + 5) ? 'text-success' : 'text-danger' }}">
                                Son tetiklenme: <strong>{{ $cronHeartbeat->format('d.m.Y H:i:s') }}</strong>
                                ({{ $mins }} dk önce)
                            </p>
                        @else
                            <p class="small text-danger mb-2">Henüz cron heartbeat yok.</p>
                        @endif
                        <p class="small eticart-muted mb-1">Örnek cron (paylaşımlı): <strong>Every 15 minutes</strong></p>
                        <pre class="bg-light p-2 rounded small user-select-all mb-0"><code>{{ $cronCommand ?? '*/15 * * * * cd ... && php artisan schedule:run >> storage/logs/cron.log 2>&1' }}</code></pre>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
