@extends('layouts.app')

@section('title', 'Senkronizasyon Ayarları')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">Senkronizasyon</h1>
            <p class="eticart-muted mb-0">Zaman aralıkları ve otomasyon seçenekleri.</p>
        </div>
        <a href="{{ route('settings.index') }}" class="btn btn-outline-secondary">Geri</a>
    </div>

  @php
      $cronMin = $cronMin ?? max(15, (int) config('eticart.schedule_cron_minutes', 15));
  @endphp

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="alert alert-info small mb-3">
                Sunucu cron en az <strong>{{ $cronMin }} dakika</strong> destekliyorsa (paylaşımlı hosting),
                aralık seçenekleri ve otomatik tarama bu süreye göre ayarlanır.
                <strong>5 dakikalık cron çoğu cPanel hostta çalışmaz.</strong>
            </div>

            <div class="eticart-card p-3 mb-3">
                <h2 class="h6 mb-2">Cron durumu</h2>
                @if ($cronHeartbeat ?? null)
                    @php $mins = $cronHeartbeat->diffInMinutes(now()); @endphp
                    <p class="small mb-2 {{ $mins <= 20 ? 'text-success' : 'text-danger' }}">
                        Son tetiklenme: <strong>{{ $cronHeartbeat->format('d.m.Y H:i:s') }}</strong>
                        ({{ $mins }} dk önce)
                    </p>
                    @if ($mins > 20)
                        <p class="small text-muted mb-0">20 dk’dan eskiyse cPanel cron komutunu ve PHP yolunu kontrol edin.</p>
                    @endif
                @else
                    <p class="small text-danger mb-2">Henüz cron heartbeat yok — <code>schedule:run</code> hiç çalışmamış olabilir.</p>
                @endif
                <p class="small eticart-muted mb-1">cPanel → Cron Jobs → <strong>Every 15 minutes</strong></p>
                <pre class="bg-light p-2 rounded small user-select-all mb-0"><code>{{ $cronCommand ?? '*/15 * * * * cd ... && php artisan schedule:run >> storage/logs/cron.log 2>&1' }}</code></pre>
            </div>

            <div class="eticart-card p-3">
                <form method="POST" action="{{ route('settings.sync.update') }}">
                    @csrf
                    @method('PUT')

                    <div class="mb-3">
                        <label class="form-label">Sipariş kontrol aralığı (dk)</label>
                        <select name="sync_orders_interval" class="form-select" required>
                            @foreach ([15, 30, 60] as $min)
                                <option value="{{ $min }}" @selected((int) old('sync_orders_interval', $settings['sync_orders_interval'] ?? $cronMin) === $min)>{{ $min }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Ürün senkronizasyonu (dk)</label>
                        <select name="sync_products_interval" class="form-select" required>
                            @foreach ([15, 30, 60, 120] as $min)
                                <option value="{{ $min }}" @selected((int) old('sync_products_interval', $settings['sync_products_interval'] ?? 30) === $min)>{{ $min }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Stok güncellemesi (dk)</label>
                        <select name="sync_stock_interval" class="form-select" required>
                            @foreach ([15, 30, 60] as $min)
                                <option value="{{ $min }}" @selected((int) old('sync_stock_interval', $settings['sync_stock_interval'] ?? $cronMin) === $min)>{{ $min }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Kargo durumu kontrol (dk)</label>
                        <select name="sync_cargo_interval" class="form-select" required>
                            @foreach ([15, 30, 60, 120] as $min)
                                <option value="{{ $min }}" @selected((int) old('sync_cargo_interval', $settings['sync_cargo_interval'] ?? $cronMin) === $min)>{{ $min }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">UyumSoft sipariş / fatura (dk)</label>
                        <select name="sync_uyumsoft_orders_interval" class="form-select" required>
                            @foreach ([15, 30, 60] as $min)
                                <option value="{{ $min }}" @selected((int) old('sync_uyumsoft_orders_interval', $settings['sync_uyumsoft_orders_interval'] ?? $cronMin) === $min)>{{ $min }}</option>
                            @endforeach
                        </select>
                        <div class="form-text">Shopify’dan çekilen yeni satışlar bu aralıkla UyumSoft’a yazılır; fatura oluşmuşsa PDF de çekilir.</div>
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
                    Sunucuda cron ile <code>php artisan schedule:run</code> çalışmalıdır (en az {{ $cronMin }} dk).
                    Aynı tetiklemede kuyruk işleri de işlenir; ayrı worker gerekmez.
                    Her tarama <strong>İşlem Geçmişi</strong> ve senkron loglarına yazılır.
                </p>
                <p class="small eticart-muted mb-2">
                    Shopify siparişleri önce panele alınır, ardından UyumSoft’a satış olarak işlenir.
                    UyumSoft’ta o siparişe fatura kesilmişse senkron sırasında PDF de buraya gelir.
                </p>
                <p class="small eticart-muted mb-0">
                    Yurtiçi Kargo standart SOAP API’sinde sipariş durumu için webhook yoktur.
                    Bu yüzden açık kargolar belirlenen aralıkla sorgulanır; şube kabulü / takip no oluşunca
                    sipariş “Kargoya verildi” olur ve Shopify’a takip bilgisi yazılır.
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
@endsection
