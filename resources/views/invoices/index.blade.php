@extends('layouts.app')

@section('title', 'Faturalar')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h1 class="h3 mb-1">Faturalar</h1>
            <p class="eticart-muted mb-0">UyumSoft’tan çekilen e-faturalar ve siparişe yüklenen belgeler.</p>
        </div>
    </div>

    <div class="eticart-card p-3 mb-3">
        <form method="GET" action="{{ route('invoices.index') }}" class="row g-2 align-items-end">
            <div class="col-12 col-md-3">
                <label class="form-label">Ara</label>
                <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" class="form-control" placeholder="Sipariş, müşteri, fatura no">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label">Kaynak</label>
                <select name="source" class="form-select">
                    <option value="">Tümü</option>
                    <option value="uyumsoft" @selected(($filters['source'] ?? '') === 'uyumsoft')>UyumSoft</option>
                    <option value="local" @selected(($filters['source'] ?? '') === 'local')>Yüklenen belge</option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label">Başlangıç</label>
                <input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="form-control">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label">Bitiş</label>
                <input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="form-control">
            </div>
            <div class="col-6 col-md-1">
                <button class="btn btn-outline-primary w-100" type="submit">Filtre</button>
            </div>
        </form>
    </div>

    @if ($orders->isEmpty())
        <x-empty-state
            title="Fatura kaydı yok"
            message="UyumSoft sipariş senkronu fatura numarasını ve e-fatura belgesini buraya getirir."
            icon="bi-receipt"
        />
    @else
        <x-table :headers="['Sipariş', 'Müşteri', 'Fatura no', 'Kaynak', 'Durum', 'Tarih', 'İşlem']">
            @foreach ($orders as $order)
                @php
                    $isUyumsoft = filled($order->uyumsoft_einvoice_uuid)
                        || filled($order->uyumsoft_invoice_id)
                        || filled($order->uyumsoft_invoice_no);
                @endphp
                <tr>
                    <td class="fw-semibold">
                        <a href="{{ route('orders.show', $order) }}">{{ $order->order_number }}</a>
                    </td>
                    <td>
                        <div>{{ $order->customer_name ?: '—' }}</div>
                        <div class="small eticart-muted">{{ $order->customer_email }}</div>
                    </td>
                    <td>
                        {{ $order->uyumsoft_invoice_no ?: '—' }}
                        @if (filled($order->uyumsoft_einvoice_uuid))
                            <div class="small eticart-muted text-truncate" style="max-width: 180px;" title="{{ $order->uyumsoft_einvoice_uuid }}">
                                {{ $order->uyumsoft_einvoice_uuid }}
                            </div>
                        @endif
                    </td>
                    <td>
                        @if ($isUyumsoft)
                            <span class="badge text-bg-primary">UyumSoft</span>
                        @endif
                        @if ($order->hasLocalInvoiceFile())
                            <span class="badge text-bg-secondary">Yüklenen</span>
                        @endif
                    </td>
                    <td><x-status-badge group="fulfillment" :value="$order->fulfillment_status" /></td>
                    <td>{{ optional($order->shopify_created_at ?? $order->created_at)->format('d.m.Y H:i') }}</td>
                    <td>
                        <div class="d-flex gap-1 flex-wrap">
                            <a href="{{ route('orders.show', $order) }}" class="btn btn-sm btn-outline-secondary">Sipariş</a>
                            @if ($order->hasInvoice() && $order->invoiceUrl())
                                <a href="{{ $order->invoiceUrl() }}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary">İndir</a>
                            @endif
                        </div>
                    </td>
                </tr>
            @endforeach
        </x-table>
        <div class="mt-3">{{ $orders->links() }}</div>
    @endif
@endsection
