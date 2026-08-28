@extends('layouts.app')

@section('title', 'Sipariş hazırlama')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h1 class="h3 mb-1">Sipariş hazırlama</h1>
            <p class="eticart-muted mb-0">Hazırlanacak siparişi seçin. Müşteri ve fatura bilgisi bu ekranda gösterilmez.</p>
        </div>
    </div>

    <div class="eticart-card p-3 mb-3">
        <form method="GET" action="{{ route('production.orders.index') }}" class="row g-2 align-items-end">
            <div class="col-12 col-md-4">
                <label class="form-label">Sipariş no</label>
                <input type="search" name="q" value="{{ $search }}" class="form-control" placeholder="#1234">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label">Durum</label>
                <select name="packed" class="form-select">
                    <option value="all" @selected($filter === 'all')>Tümü</option>
                    <option value="0" @selected($filter === '0')>Bekleyen</option>
                    <option value="1" @selected($filter === '1')>Hazırlanan</option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <button class="btn btn-outline-primary w-100" type="submit">Filtrele</button>
            </div>
        </form>
    </div>

    @if ($orders->isEmpty())
        <x-empty-state title="Sipariş yok" message="Bu filtreye uyan hazırlama kaydı bulunamadı." icon="bi-box-seam" />
    @else
        <x-table :headers="['Sipariş', 'Kalem', 'Adet', 'Tarih', 'Durum', '']">
            @foreach ($orders as $order)
                <tr class="order-row {{ $order->isPacked() ? 'is-packed' : '' }}">
                    <td class="fw-semibold">
                        <span class="d-inline-flex align-items-center gap-2">
                            <x-order-packed-mark :order="$order" />
                            <span>{{ $order->order_number }}</span>
                        </span>
                    </td>
                    <td>{{ $order->items->count() }}</td>
                    <td>{{ $order->items->sum('quantity') }}</td>
                    <td>{{ optional($order->shopify_created_at ?? $order->created_at)->format('d.m.Y H:i') }}</td>
                    <td>
                        @if ($order->isPacked())
                            <span class="badge text-bg-success">Hazırlandı</span>
                        @elseif ($order->packing_started_by_user_id)
                            <span class="badge text-bg-warning">{{ $order->packingStarterName() }} hazırlıyor</span>
                        @else
                            <span class="badge text-bg-secondary">Bekliyor</span>
                        @endif
                    </td>
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
        </x-table>
        <div class="mt-3">{{ $orders->links() }}</div>
    @endif
@endsection
