@extends('layouts.app')

@section('title', 'Kargo Raporu')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h1 class="h3 mb-1">Kargo Raporu</h1>
            <p class="eticart-muted mb-0">Gönderi durumları ve firma dağılımı.</p>
        </div>
        <a href="{{ route('reports.index') }}" class="btn btn-outline-secondary btn-sm">Geri</a>
    </div>

    <div class="eticart-card p-3 mb-3">
        <form method="GET" action="{{ route('reports.shipments') }}" class="row g-2 align-items-end">
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
        <div class="col-6 col-md-3">
            <div class="eticart-stat-card">
                <div class="eticart-muted small">Toplam</div>
                <div class="fs-4 fw-semibold">{{ number_format($report['summary']['total']) }}</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="eticart-stat-card">
                <div class="eticart-muted small">Teslim</div>
                <div class="fs-4 fw-semibold">{{ number_format($report['summary']['delivered']) }}</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="eticart-stat-card">
                <div class="eticart-muted small">Yolda</div>
                <div class="fs-4 fw-semibold">{{ number_format($report['summary']['shipped']) }}</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="eticart-stat-card">
                <div class="eticart-muted small">Kargo Maliyeti</div>
                <div class="fs-4 fw-semibold">₺{{ number_format($report['summary']['cargo_cost'], 2) }}</div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="eticart-card p-3">
                <h2 class="h6 mb-3">Duruma Göre</h2>
                @forelse ($report['by_status'] as $status => $count)
                    <div class="d-flex justify-content-between border-bottom py-2">
                        <span>{{ \App\Support\StatusLabels::shipment($status) }}</span>
                        <strong>{{ $count }}</strong>
                    </div>
                @empty
                    <p class="eticart-muted mb-0">Veri yok.</p>
                @endforelse
            </div>
        </div>
        <div class="col-md-6">
            <div class="eticart-card p-3">
                <h2 class="h6 mb-3">Firmaya Göre</h2>
                @forelse ($report['by_company'] as $company => $count)
                    <div class="d-flex justify-content-between border-bottom py-2">
                        <span>{{ $company }}</span>
                        <strong>{{ $count }}</strong>
                    </div>
                @empty
                    <p class="eticart-muted mb-0">Veri yok.</p>
                @endforelse
            </div>
        </div>
    </div>

    @if ($report['shipments']->isEmpty())
        <x-empty-state title="Gönderi yok" message="Seçilen aralıkta kargo kaydı bulunamadı." icon="bi-truck" />
    @else
        <x-table :headers="['Sipariş', 'Takip No', 'Firma', 'Durum', 'Tarih']">
            @foreach ($report['shipments']->take(50) as $shipment)
                <tr>
                    <td>{{ $shipment->order_number }}</td>
                    <td>{{ $shipment->tracking_number ?: '-' }}</td>
                    <td>{{ $shipment->cargoCompany?->name ?? '-' }}</td>
                    <td><x-badge type="info">{{ $shipment->status }}</x-badge></td>
                    <td>{{ optional($shipment->created_at)->format('d.m.Y H:i') }}</td>
                </tr>
            @endforeach
        </x-table>
    @endif
@endsection
