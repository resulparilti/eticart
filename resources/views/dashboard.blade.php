@extends('layouts.app')

@section('title', 'Anasayfa')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h1 class="h3 mb-1">Anasayfa</h1>
            <p class="eticart-muted mb-0">{{ \App\Models\Setting::appName() }} entegrasyon paneline hoş geldiniz.</p>
        </div>
        <span class="badge text-bg-secondary">Localhost</span>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-12 col-sm-6 col-xl-3">
            <a href="{{ route('orders.index') }}" class="eticart-stat-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="eticart-muted small">Siparişler</div>
                        <div class="fs-3 fw-semibold">{{ number_format($stats['orders']) }}</div>
                    </div>
                    <div class="eticart-stat-card__icon text-bg-primary">
                        <i class="bi bi-bag-check"></i>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <a href="{{ route('products.index') }}" class="eticart-stat-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="eticart-muted small">Ürünler</div>
                        <div class="fs-3 fw-semibold">{{ number_format($stats['products']) }}</div>
                    </div>
                    <div class="eticart-stat-card__icon text-bg-info">
                        <i class="bi bi-box-seam"></i>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <a href="{{ route('reports.sales') }}" class="eticart-stat-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="eticart-muted small">Ciro</div>
                        <div class="fs-3 fw-semibold">₺{{ number_format($stats['revenue'], 2) }}</div>
                    </div>
                    <div class="eticart-stat-card__icon text-bg-success">
                        <i class="bi bi-currency-exchange"></i>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <a href="{{ route('shipments.index') }}" class="eticart-stat-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="eticart-muted small">Kargolar</div>
                        <div class="fs-3 fw-semibold">{{ number_format($stats['shipments']) }}</div>
                    </div>
                    <div class="eticart-stat-card__icon text-bg-warning">
                        <i class="bi bi-truck"></i>
                    </div>
                </div>
            </a>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-12 col-xl-8">
            <div class="eticart-card p-3 h-100">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h2 class="h5 mb-0">Son Siparişler</h2>
                    <a href="{{ route('orders.index') }}" class="small text-decoration-none">Tümünü gör</a>
                </div>

                @if ($recentOrders->isEmpty())
                    <x-empty-state
                        title="Henüz sipariş yok"
                        message="Shopify senkronizasyonu sonrası siparişler burada listelenecek."
                        icon="bi-bag"
                    />
                @else
                    <x-table :headers="['Sipariş #', 'Müşteri', 'Tutar', 'Durum', 'Tarih']">
                        @foreach ($recentOrders as $order)
                            <tr>
                                <td>
                                    <a href="{{ route('orders.show', $order) }}" class="fw-semibold text-decoration-none">
                                        {{ $order->order_number }}
                                    </a>
                                </td>
                                <td>{{ $order->customer_name }}</td>
                                <td>₺{{ number_format((float) $order->total_price, 2) }}</td>
                                <td><x-status-badge group="fulfillment" :value="$order->fulfillment_status" /></td>
                                <td>{{ optional($order->created_at)->format('d.m.Y H:i') }}</td>
                            </tr>
                        @endforeach
                    </x-table>
                @endif
            </div>
        </div>

        <div class="col-12 col-xl-4">
            <div class="eticart-card p-3 h-100">
                <h2 class="h5 mb-3">Senkronizasyon Durumu</h2>
                <ul class="list-group list-group-flush mb-3">
                    @foreach ($syncStatus as $sync)
                        <li class="list-group-item px-0 d-flex justify-content-between align-items-center bg-transparent">
                            <div>
                                <div class="fw-medium">{{ $sync['name'] }}</div>
                                <small class="eticart-muted">
                                    {{ $sync['last_run'] ? $sync['last_run'] : 'Henüz çalışmadı' }}
                                </small>
                            </div>
                            <x-badge type="{{ $sync['status'] === 'idle' ? 'secondary' : ($sync['status'] === 'running' ? 'info' : 'warning') }}">
                                {{ $sync['status'] === 'idle' ? 'Bekliyor' : ucfirst((string) $sync['status']) }}
                            </x-badge>
                        </li>
                    @endforeach
                </ul>
                <a href="{{ route('settings.sync') }}" class="btn btn-sm btn-outline-secondary">Senkronizasyon ayarları & cron</a>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-12 col-lg-6">
            <div class="eticart-card p-3 h-100">
                <h2 class="h5 mb-3">Sistem Sağlığı</h2>
                <div class="row g-2">
                    <div class="col-6">
                        <div class="border rounded p-3">
                            <div class="eticart-muted small">Veritabanı</div>
                            <div class="fw-semibold {{ $systemHealth['database'] ? 'text-success' : 'text-danger' }}">
                                {{ $systemHealth['database'] ? 'Bağlı' : 'Hata' }}
                            </div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="border rounded p-3">
                            <div class="eticart-muted small">Queue</div>
                            <div class="fw-semibold {{ $systemHealth['queue'] ? 'text-success' : 'text-danger' }}">
                                {{ config('queue.default') }}
                            </div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="border rounded p-3">
                            <div class="eticart-muted small">Cache</div>
                            <div class="fw-semibold {{ $systemHealth['cache'] ? 'text-success' : 'text-danger' }}">
                                {{ config('cache.default') }}
                            </div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="border rounded p-3">
                            <div class="eticart-muted small">Mail</div>
                            <div class="fw-semibold {{ $systemHealth['mail'] ? 'text-success' : 'text-danger' }}">
                                {{ config('mail.default') }}
                            </div>
                        </div>
                    </div>
                    <div class="col-12">
                        @php
                            $diskUsage = $diskUsage ?? [];
                            $diskPercent = $diskUsage['used_percent'] ?? null;
                            $diskTone = $diskPercent === null ? 'secondary' : ($diskPercent >= 90 ? 'danger' : ($diskPercent >= 75 ? 'warning' : 'success'));
                        @endphp
                        <div class="border rounded p-3">
                            <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                                <div>
                                    <div class="eticart-muted small">Disk kullanımı</div>
                                    <div class="fw-semibold">{{ $diskUsage['total'] ?? '—' }}</div>
                                </div>
                                @if ($diskPercent !== null)
                                    <span class="badge text-bg-{{ $diskTone }}">Disk %{{ number_format((float) $diskPercent, 1) }}</span>
                                @endif
                            </div>
                            <div class="small">
                                <div class="d-flex justify-content-between"><span class="eticart-muted">Yazılım</span><span>{{ $diskUsage['software'] ?? '—' }}</span></div>
                                <div class="d-flex justify-content-between"><span class="eticart-muted">Faturalar</span><span>{{ $diskUsage['invoices'] ?? '—' }}</span></div>
                                <div class="d-flex justify-content-between"><span class="eticart-muted">Görseller</span><span>{{ $diskUsage['images'] ?? '—' }}</span></div>
                            </div>
                            @if (! empty($diskUsage['disk_total_bytes']))
                                <div class="progress mt-2" style="height: 6px;" role="progressbar" aria-valuenow="{{ (int) $diskPercent }}" aria-valuemin="0" aria-valuemax="100">
                                    <div class="progress-bar bg-{{ $diskTone }}" style="width: {{ min(100, (float) $diskPercent) }}%"></div>
                                </div>
                                <div class="eticart-muted small mt-1">Sunucu: {{ $diskUsage['disk_free'] }} boş / {{ $diskUsage['disk_total'] }} toplam</div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-6">
            <div class="eticart-card p-3 h-100">
                <h2 class="h5 mb-3">Haftalık Özet</h2>
                <canvas id="dashboardChart" height="140" aria-label="Haftalık özet grafiği"></canvas>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const canvas = document.getElementById('dashboardChart');
        if (!canvas || !window.Chart) {
            return;
        }

        new window.Chart(canvas, {
            type: 'line',
            data: {
                labels: ['Pzt', 'Sal', 'Çar', 'Per', 'Cum', 'Cmt', 'Paz'],
                datasets: [{
                    label: 'Sipariş',
                    data: [0, 0, 0, 0, 0, 0, 0],
                    borderColor: '#E67E22',
                    backgroundColor: 'rgba(230, 126, 34, 0.15)',
                    tension: 0.35,
                    fill: true,
                }],
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { display: false },
                },
                scales: {
                    y: { beginAtZero: true, ticks: { precision: 0 } },
                },
            },
        });
    });
</script>
@endpush
