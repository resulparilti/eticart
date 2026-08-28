@extends('layouts.app')

@section('title', 'Üretim')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h1 class="h3 mb-1">Üretim</h1>
            <p class="eticart-muted mb-0">Bugünkü sipariş ve stok özeti.</p>
        </div>
        <span class="badge text-bg-secondary">{{ now()->format('d.m.Y') }}</span>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-12 col-sm-6 col-xl">
            <a href="{{ route('production.orders.index', ['packed' => 'all']) }}" class="eticart-stat-card">
                <div class="eticart-muted small">Bugün gelen sipariş</div>
                <div class="fs-3 fw-semibold">{{ number_format($stats['total']) }}</div>
            </a>
        </div>
        <div class="col-12 col-sm-6 col-xl">
            <a href="{{ route('production.orders.index', ['packed' => '0']) }}" class="eticart-stat-card">
                <div class="eticart-muted small">Hazırlanmayı bekleyen</div>
                <div class="fs-3 fw-semibold">{{ number_format($stats['awaiting']) }}</div>
            </a>
        </div>
        <div class="col-12 col-sm-6 col-xl">
            <a href="{{ route('production.orders.index', ['packed' => '0']) }}" class="eticart-stat-card">
                <div class="eticart-muted small">Devam ettiklerim</div>
                <div class="fs-3 fw-semibold">{{ number_format($stats['in_progress']) }}</div>
            </a>
        </div>
        <div class="col-12 col-sm-6 col-xl">
            <a href="{{ route('production.orders.index', ['packed' => 1]) }}" class="eticart-stat-card">
                <div class="eticart-muted small">Bugün hazırladıklarım</div>
                <div class="fs-3 fw-semibold">{{ number_format($stats['packed']) }}</div>
            </a>
        </div>
        <div class="col-12 col-sm-6 col-xl">
            <div class="eticart-stat-card">
                <div class="eticart-muted small">Bugün teslim</div>
                <div class="fs-3 fw-semibold">{{ number_format($stats['delivered']) }}</div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-12 col-xl-7">
            <div class="eticart-card p-3 h-100">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h2 class="h5 mb-0">Hazırlanmayı bekleyen siparişler</h2>
                    <a href="{{ route('production.orders.index', ['packed' => '0']) }}" class="small text-decoration-none">Tümü</a>
                </div>
                @if ($awaitingOrders->isEmpty())
                    <x-empty-state title="Bekleyen sipariş yok" message="Hazırlanacak açık sipariş bulunmuyor." icon="bi-box-seam" />
                @else
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Sipariş</th>
                                    <th>Ürün</th>
                                    <th>Adet</th>
                                    <th>Tarih</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($awaitingOrders as $order)
                                    <tr>
                                        <td class="fw-semibold">{{ $order->order_number }}</td>
                                        <td>{{ $order->items->count() }} kalem</td>
                                        <td>{{ $order->items->sum('quantity') }}</td>
                                        <td>{{ optional($order->shopify_created_at ?? $order->created_at)->format('d.m.Y H:i') }}</td>
                                        <td class="text-end">
                                            @if ($order->isPacked() || $order->isPackingClaimedByOther(auth()->user()))
                                                <a href="{{ route('production.orders.show', $order) }}" class="btn btn-sm btn-outline-secondary">Görüntüle</a>
                                            @else
                                                <a href="{{ route('production.orders.show', $order) }}"
                                                   class="btn btn-sm btn-primary"
                                                   data-pack-claim-url="{{ route('orders.packing.claim', $order) }}">{{ $order->packing_started_by_user_id ? 'Devam et' : 'Hazırla' }}</a>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
        <div class="col-12 col-xl-5">
            <div class="eticart-card p-3 h-100 d-flex flex-column">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h2 class="h5 mb-0">Stok tükenmek üzere</h2>
                    <a href="{{ route('production.products.index', ['stock' => 'low']) }}" class="small text-decoration-none">Ürünler</a>
                </div>
                <p class="small eticart-muted">{{ $lowStockThreshold }} adet ve altı varyantlar.</p>
                @if ($lowStockGroups === [])
                    <x-empty-state title="Kritik stok yok" message="Tükenmek üzere ürün görünmüyor." icon="bi-clipboard-check" />
                @else
                    <ul class="list-unstyled mb-0 production-stock-list">
                        @foreach ($lowStockGroups as $group)
                            <li class="mb-3">
                                <a href="{{ route('production.products.show', $group['product']) }}" class="fw-semibold text-decoration-none">
                                    {{ $group['product']->title }}
                                </a>
                                <div class="small eticart-muted">Ana stok: {{ $group['product']->stock }}</div>
                                <ul class="list-unstyled ms-3 mt-1 mb-0">
                                    @foreach ($group['variants'] as $variant)
                                        <li class="d-flex justify-content-between gap-2 small py-1 border-bottom production-stock-variant">
                                            <span>
                                                {{ $variant['title'] }}
                                                @if ($variant['barcode'])
                                                    <span class="eticart-muted">· {{ $variant['barcode'] }}</span>
                                                @endif
                                            </span>
                                            <span class="fw-semibold {{ $variant['stock'] <= 0 ? 'text-danger' : 'text-warning' }}">
                                                {{ $variant['stock'] }}
                                            </span>
                                        </li>
                                    @endforeach
                                </ul>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </div>
@endsection
