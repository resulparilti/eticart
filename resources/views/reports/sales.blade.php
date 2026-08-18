@extends('layouts.app')

@section('title', 'Satış Raporu')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h1 class="h3 mb-1">Satış Raporu</h1>
            <p class="eticart-muted mb-0">Seçilen aralıktaki sipariş ve ciro özeti.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('reports.sales.export.csv', ['from' => $dateFrom, 'to' => $dateTo]) }}" class="btn btn-outline-secondary btn-sm">CSV</a>
            <a href="{{ route('reports.sales.export.pdf', ['from' => $dateFrom, 'to' => $dateTo]) }}" class="btn btn-outline-secondary btn-sm">PDF</a>
            <a href="{{ route('reports.index') }}" class="btn btn-outline-secondary btn-sm">Geri</a>
        </div>
    </div>

    <div class="eticart-card p-3 mb-3">
        <form method="GET" action="{{ route('reports.sales') }}" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label">Başlangıç</label>
                <input type="date" name="from" value="{{ $dateFrom }}" class="form-control" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">Bitiş</label>
                <input type="date" name="to" value="{{ $dateTo }}" class="form-control" required>
            </div>
            <div class="col-md-4">
                <button class="btn btn-primary w-100" type="submit">Filtrele</button>
            </div>
        </form>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="eticart-stat-card">
                <div class="eticart-muted small">Sipariş</div>
                <div class="fs-3 fw-semibold">{{ number_format($report['summary']['orders']) }}</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="eticart-stat-card">
                <div class="eticart-muted small">Ciro</div>
                <div class="fs-3 fw-semibold">₺{{ number_format($report['summary']['revenue'], 2) }}</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="eticart-stat-card">
                <div class="eticart-muted small">Ortalama Sipariş</div>
                <div class="fs-3 fw-semibold">₺{{ number_format($report['summary']['avg_order'], 2) }}</div>
            </div>
        </div>
    </div>

    <div class="eticart-card p-3 mb-4">
        <h2 class="h6 mb-3">Günlük Ciro</h2>
        <canvas id="salesChart" height="120" aria-label="Satış grafiği"></canvas>
    </div>

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="eticart-card p-3">
                <h2 class="h6 mb-3">Günlük Özet</h2>
                @if (empty($report['daily']))
                    <x-empty-state title="Veri yok" message="Seçilen aralıkta sipariş bulunamadı." icon="bi-graph-up" />
                @else
                    <x-table :headers="['Tarih', 'Sipariş', 'Ciro']">
                        @foreach ($report['daily'] as $row)
                            <tr>
                                <td>{{ \Illuminate\Support\Carbon::parse($row['date'])->format('d.m.Y') }}</td>
                                <td>{{ $row['orders'] }}</td>
                                <td>₺{{ number_format($row['revenue'], 2) }}</td>
                            </tr>
                        @endforeach
                    </x-table>
                @endif
            </div>
        </div>
        <div class="col-lg-6">
            <div class="eticart-card p-3">
                <h2 class="h6 mb-3">Ürün Satışları</h2>
                @if ($products['items']->isEmpty())
                    <p class="eticart-muted mb-0">Ürün satışı yok.</p>
                @else
                    <x-table :headers="['Ürün', 'SKU', 'Adet', 'Ciro']">
                        @foreach ($products['items'] as $item)
                            <tr>
                                <td>
                                    <div class="fw-semibold">{{ $item->product_title }}</div>
                                    @if ($item->variant_title)
                                        <div class="small eticart-muted">{{ $item->variant_title }}</div>
                                    @endif
                                </td>
                                <td>{{ $item->sku ?: '-' }}</td>
                                <td>{{ (int) $item->total_qty }}</td>
                                <td>₺{{ number_format((float) $item->total_revenue, 2) }}</td>
                            </tr>
                        @endforeach
                    </x-table>
                @endif
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const canvas = document.getElementById('salesChart');
        if (!canvas || !window.Chart) return;

        const labels = @json($report['chart']['labels']);
        const revenue = @json($report['chart']['revenue']);
        const orders = @json($report['chart']['orders']);

        new window.Chart(canvas, {
            type: 'line',
            data: {
                labels,
                datasets: [
                    {
                        label: 'Ciro (₺)',
                        data: revenue,
                        borderColor: '#0d6efd',
                        backgroundColor: 'rgba(13,110,253,.12)',
                        tension: 0.3,
                        yAxisID: 'y',
                    },
                    {
                        label: 'Sipariş',
                        data: orders,
                        borderColor: '#198754',
                        backgroundColor: 'rgba(25,135,84,.12)',
                        tension: 0.3,
                        yAxisID: 'y1',
                    },
                ],
            },
            options: {
                responsive: true,
                interaction: { mode: 'index', intersect: false },
                scales: {
                    y: { type: 'linear', position: 'left' },
                    y1: { type: 'linear', position: 'right', grid: { drawOnChartArea: false } },
                },
            },
        });
    });
</script>
@endpush
