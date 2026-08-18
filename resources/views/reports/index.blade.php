@extends('layouts.app')

@section('title', 'Raporlar')

@section('content')
    <div class="mb-4">
        <h1 class="h3 mb-1">Raporlar</h1>
        <p class="eticart-muted mb-0">Satış, senkron ve sistem özetleri.</p>
    </div>

    <div class="row g-3">
        <div class="col-md-6 col-xl-3">
            <a href="{{ route('reports.sales') }}" class="eticart-card p-3 d-block text-decoration-none h-100">
                <div class="d-flex align-items-center gap-3">
                    <div class="eticart-stat-card__icon text-bg-success"><i class="bi bi-graph-up"></i></div>
                    <div>
                        <div class="fw-semibold text-body">Satış Raporu</div>
                        <div class="eticart-muted small">Ciro, sipariş ve ürün satışları</div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-6 col-xl-3">
            <a href="{{ route('reports.shipments') }}" class="eticart-card p-3 d-block text-decoration-none h-100">
                <div class="d-flex align-items-center gap-3">
                    <div class="eticart-stat-card__icon text-bg-primary"><i class="bi bi-truck"></i></div>
                    <div>
                        <div class="fw-semibold text-body">Kargo Raporu</div>
                        <div class="eticart-muted small">Durum ve firma dağılımı</div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-6 col-xl-3">
            <a href="{{ route('reports.sync-logs') }}" class="eticart-card p-3 d-block text-decoration-none h-100">
                <div class="d-flex align-items-center gap-3">
                    <div class="eticart-stat-card__icon text-bg-info"><i class="bi bi-arrow-repeat"></i></div>
                    <div>
                        <div class="fw-semibold text-body">Senkron Logları</div>
                        <div class="eticart-muted small">Başarı / hata geçmişi</div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-6 col-xl-3">
            <a href="{{ route('reports.system-logs') }}" class="eticart-card p-3 d-block text-decoration-none h-100">
                <div class="d-flex align-items-center gap-3">
                    <div class="eticart-stat-card__icon text-bg-warning"><i class="bi bi-bug"></i></div>
                    <div>
                        <div class="fw-semibold text-body">Sistem Logları</div>
                        <div class="eticart-muted small">Uygulama ve API hataları</div>
                    </div>
                </div>
            </a>
        </div>
    </div>
@endsection
