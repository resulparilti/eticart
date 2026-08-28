@extends('layouts.app')

@section('title', 'Silinen sipariş arşivi')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h1 class="h3 mb-1">Silinen sipariş arşivi</h1>
            <p class="eticart-muted mb-0">Shopify’dan silinen siparişlerin kaydı. Canlı listeden kaldırılır, ayrıntılar burada saklanır.</p>
        </div>
        <a href="{{ route('orders.index') }}" class="btn btn-outline-secondary">Siparişlere dön</a>
    </div>

    <div class="eticart-card p-3 mb-3">
        <form method="GET" action="{{ route('orders.archives.index') }}" class="row g-2 align-items-end">
            <div class="col-12 col-md-4">
                <label class="form-label">Ara</label>
                <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" class="form-control" placeholder="Sipariş no, müşteri, Shopify ID">
            </div>
            <div class="col-6 col-md-2">
                <button class="btn btn-outline-primary w-100" type="submit">Filtre</button>
            </div>
        </form>
    </div>

    @if ($archives->isEmpty())
        <x-empty-state
            title="Arşiv kaydı yok"
            message="Shopify’dan silinen siparişler senkron sırasında buraya taşınır."
            icon="bi-archive"
        />
    @else
        <x-table :headers="['Sipariş', 'Müşteri', 'Tutar', 'Durum', 'Neden', 'Arşiv tarihi', '']">
            @foreach ($archives as $archive)
                <tr>
                    <td class="fw-semibold">
                        <a href="{{ route('orders.archives.show', $archive) }}">{{ $archive->order_number }}</a>
                        <div class="small eticart-muted">Shopify ID: {{ $archive->shopify_order_id }}</div>
                    </td>
                    <td>
                        <div>{{ $archive->customer_name ?: '—' }}</div>
                        <div class="small eticart-muted">{{ $archive->customer_email ?: '' }}</div>
                    </td>
                    <td>₺{{ number_format((float) $archive->total_price, 2) }} {{ $archive->currency }}</td>
                    <td>{{ $archive->fulfillment_status ?: '—' }}</td>
                    <td>{{ $archive->reasonLabel() }}</td>
                    <td>{{ optional($archive->archived_at)->format('d.m.Y H:i') }}</td>
                    <td>
                        <a href="{{ route('orders.archives.show', $archive) }}" class="btn btn-sm btn-outline-secondary">Detay</a>
                    </td>
                </tr>
            @endforeach
        </x-table>
        <div class="mt-3">{{ $archives->links() }}</div>
    @endif
@endsection
