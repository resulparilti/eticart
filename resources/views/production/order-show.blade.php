@extends('layouts.app')

@section('title', 'Hazırlama '.$order->order_number)

@section('content')
    <div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
        <div>
            <h1 class="h3 mb-1">{{ $order->order_number }}</h1>
            <p class="eticart-muted mb-0">
                {{ optional($order->shopify_created_at ?? $order->created_at)->format('d.m.Y H:i') }}
            </p>
        </div>
        <a href="{{ route('production.orders.index') }}" class="btn btn-outline-secondary">Listeye dön</a>
    </div>

    <div class="eticart-card p-3 mb-3">
        <h2 class="h5 mb-3">Ürünler</h2>
        @if ($lines->isEmpty())
            <x-empty-state title="Kalem yok" message="Bu siparişte ürün kalemi bulunamadı." icon="bi-box" />
        @else
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="width: 72px;">Görsel</th>
                            <th>Ürün</th>
                            <th>Adet</th>
                            <th>Barkod</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($lines as $line)
                            @php $item = $line['item']; @endphp
                            <tr>
                                <td>
                                    <x-product-list-thumb :url="$line['image']" :alt="$item->product_title" />
                                </td>
                                <td>
                                    <div class="fw-semibold">{{ $item->product_title }}</div>
                                    @if ($item->variant_title)
                                        <div class="small eticart-muted">{{ $item->variant_title }}</div>
                                    @endif
                                    @if ($item->sku)
                                        <div class="small eticart-muted">SKU: {{ $item->sku }}</div>
                                    @endif
                                </td>
                                <td class="fs-5 fw-semibold">{{ $item->quantity }}</td>
                                <td class="font-monospace">{{ $line['barcode'] ?: '-' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    @include('orders.partials.packing-panel', ['order' => $order])
@endsection
