@extends('layouts.app')

@section('title', 'Arşiv '.$archive->order_number)

@section('content')
    <div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
        <div>
            <h1 class="h3 mb-1">{{ $archive->order_number }}</h1>
            <p class="eticart-muted mb-0">Shopify ID: {{ $archive->shopify_order_id }} · {{ $archive->reasonLabel() }}</p>
        </div>
        <a href="{{ route('orders.archives.index') }}" class="btn btn-outline-secondary">Arşive dön</a>
    </div>

    <div class="alert alert-warning" role="alert">
        Bu sipariş Shopify’dan silindiği için canlı listeden kaldırıldı.
        Kayıt {{ optional($archive->archived_at)->format('d.m.Y H:i') }} tarihinde arşivlendi.
    </div>

    <div class="row g-3 mb-3">
        <div class="col-12 col-lg-6">
            <div class="eticart-card p-3 h-100">
                <h2 class="h5 mb-3">Müşteri</h2>
                <dl class="row mb-0 small">
                    <dt class="col-4 eticart-muted">Ad</dt>
                    <dd class="col-8">{{ $archive->customer_name ?: '—' }}</dd>
                    <dt class="col-4 eticart-muted">E-posta</dt>
                    <dd class="col-8">{{ $archive->customer_email ?: '—' }}</dd>
                    <dt class="col-4 eticart-muted">Telefon</dt>
                    <dd class="col-8">{{ $archive->customer_phone ?: '—' }}</dd>
                </dl>
            </div>
        </div>
        <div class="col-12 col-lg-6">
            <div class="eticart-card p-3 h-100">
                <h2 class="h5 mb-3">Özet</h2>
                <dl class="row mb-0 small">
                    <dt class="col-4 eticart-muted">Tutar</dt>
                    <dd class="col-8">₺{{ number_format((float) $archive->total_price, 2) }} {{ $archive->currency }}</dd>
                    <dt class="col-4 eticart-muted">Ödeme</dt>
                    <dd class="col-8">{{ $archive->payment_status ?: '—' }}</dd>
                    <dt class="col-4 eticart-muted">Durum</dt>
                    <dd class="col-8">{{ $archive->fulfillment_status ?: '—' }}</dd>
                    <dt class="col-4 eticart-muted">Neden</dt>
                    <dd class="col-8">{{ $archive->reasonLabel() }}</dd>
                </dl>
            </div>
        </div>
    </div>

    <div class="eticart-card p-3 mb-3">
        <h2 class="h5 mb-3">Kalemler</h2>
        @php $items = $archive->snapshotItems(); @endphp
        @if ($items === [])
            <x-empty-state title="Kalem yok" message="Arşiv kaydında ürün kalemi bulunamadı." icon="bi-box" />
        @else
            <x-table :headers="['Ürün', 'SKU', 'Adet', 'Fiyat']">
                @foreach ($items as $item)
                    <tr>
                        <td>{{ $item['product_title'] ?? ($item['title'] ?? '—') }}</td>
                        <td>{{ $item['sku'] ?? '—' }}</td>
                        <td>{{ $item['quantity'] ?? '—' }}</td>
                        <td>₺{{ number_format((float) ($item['price'] ?? 0), 2) }}</td>
                    </tr>
                @endforeach
            </x-table>
        @endif
    </div>
@endsection
