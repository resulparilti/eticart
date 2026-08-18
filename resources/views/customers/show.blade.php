@extends('layouts.app')

@section('title', $customer->displayName())

@section('content')
    <div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
        <div>
            <h1 class="h3 mb-1">{{ $customer->displayName() }}</h1>
            <p class="eticart-muted mb-0">
                @if ($customer->shopify_customer_id)
                    Shopify ID: {{ $customer->shopify_customer_id }}
                @else
                    Yerel kayıt (siparişlerden)
                @endif
            </p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            @if ($customer->shopify_customer_id)
                <form method="POST" action="{{ route('customers.refresh', $customer) }}">
                    @csrf
                    <button type="submit" class="btn btn-outline-primary" @disabled(! $shopifyConfigured)>
                        Shopify’dan Yenile
                    </button>
                </form>
            @endif
            <a href="{{ route('customers.index') }}" class="btn btn-outline-secondary">Geri</a>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-lg-4">
            <div class="eticart-card p-3 h-100">
                <h2 class="h6 mb-3">İletişim</h2>
                <dl class="row mb-0 small">
                    <dt class="col-4 eticart-muted">E-posta</dt>
                    <dd class="col-8">{{ $customer->email ?: '—' }}</dd>
                    <dt class="col-4 eticart-muted">Telefon</dt>
                    <dd class="col-8">{{ $customer->phone ?: '—' }}</dd>
                    <dt class="col-4 eticart-muted">Şirket</dt>
                    <dd class="col-8">{{ $customer->company ?: '—' }}</dd>
                    <dt class="col-4 eticart-muted">Durum</dt>
                    <dd class="col-8">{{ $customer->state ?: '—' }}</dd>
                    <dt class="col-4 eticart-muted">Son sync</dt>
                    <dd class="col-8">{{ optional($customer->last_sync)->format('d.m.Y H:i') ?: '—' }}</dd>
                </dl>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="eticart-card p-3 h-100">
                <h2 class="h6 mb-3">Adres</h2>
                <dl class="row mb-0 small">
                    <dt class="col-4 eticart-muted">Adres</dt>
                    <dd class="col-8">{{ $customer->address ?: '—' }}</dd>
                    <dt class="col-4 eticart-muted">Şehir</dt>
                    <dd class="col-8">{{ $customer->city ?: '—' }}</dd>
                    <dt class="col-4 eticart-muted">İl / Eyalet</dt>
                    <dd class="col-8">{{ $customer->province ?: '—' }}</dd>
                    <dt class="col-4 eticart-muted">Ülke</dt>
                    <dd class="col-8">{{ $customer->country ?: '—' }}</dd>
                    <dt class="col-4 eticart-muted">Posta</dt>
                    <dd class="col-8">{{ $customer->zip ?: '—' }}</dd>
                </dl>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="eticart-card p-3 h-100">
                <h2 class="h6 mb-3">Özet</h2>
                <dl class="row mb-0 small">
                    <dt class="col-5 eticart-muted">Sipariş sayısı</dt>
                    <dd class="col-7">{{ $customer->orders_count }}</dd>
                    <dt class="col-5 eticart-muted">Toplam harcama</dt>
                    <dd class="col-7">₺{{ number_format((float) $customer->total_spent, 2) }}</dd>
                    <dt class="col-5 eticart-muted">Son sipariş</dt>
                    <dd class="col-7">{{ optional($customer->last_order_at)->format('d.m.Y H:i') ?: '—' }}</dd>
                    <dt class="col-5 eticart-muted">Shopify kayıt</dt>
                    <dd class="col-7">{{ optional($customer->shopify_created_at)->format('d.m.Y') ?: '—' }}</dd>
                </dl>
                @if ($customer->tagList() !== [])
                    <div class="mt-3 d-flex flex-wrap gap-1">
                        @foreach ($customer->tagList() as $tag)
                            <span class="badge text-bg-light border">{{ $tag }}</span>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>

    @if ($customer->note)
        <div class="eticart-card p-3 mb-3">
            <h2 class="h6 mb-2">Not</h2>
            <div class="small" style="white-space: pre-wrap;">{{ $customer->note }}</div>
        </div>
    @endif

    @if (! empty($customer->addresses) && is_array($customer->addresses))
        <div class="eticart-card p-3 mb-3">
            <h2 class="h6 mb-3">Kayıtlı adresler ({{ count($customer->addresses) }})</h2>
            <div class="row g-3">
                @foreach ($customer->addresses as $address)
                    @if (is_array($address))
                        <div class="col-md-6">
                            <div class="border rounded p-3 small">
                                <div class="fw-semibold mb-1">
                                    {{ trim(($address['first_name'] ?? '').' '.($address['last_name'] ?? '')) ?: ($address['name'] ?? 'Adres') }}
                                </div>
                                <div>{{ $address['address1'] ?? '' }}</div>
                                @if (! empty($address['address2']))
                                    <div>{{ $address['address2'] }}</div>
                                @endif
                                <div>
                                    {{ collect([$address['city'] ?? null, $address['province'] ?? null, $address['zip'] ?? null])->filter()->implode(', ') }}
                                </div>
                                <div>{{ $address['country'] ?? '' }}</div>
                                @if (! empty($address['phone']))
                                    <div class="eticart-muted mt-1">{{ $address['phone'] }}</div>
                                @endif
                            </div>
                        </div>
                    @endif
                @endforeach
            </div>
        </div>
    @endif

    <div class="eticart-card p-3">
        <h2 class="h6 mb-3">Siparişler</h2>
        @if ($orders->isEmpty())
            <p class="eticart-muted mb-0">Bağlı sipariş yok.</p>
        @else
            <x-table :headers="['Sipariş', 'Tutar', 'Ödeme', 'Fulfillment', 'Tarih', '']">
                @foreach ($orders as $order)
                    <tr>
                        <td>{{ $order->order_number }}</td>
                        <td>₺{{ number_format((float) $order->total_price, 2) }} {{ $order->currency }}</td>
                        <td>{{ $order->payment_status ?: '—' }}</td>
                        <td>{{ $order->fulfillment_status ?: '—' }}</td>
                        <td>{{ optional($order->shopify_created_at)->format('d.m.Y H:i') ?: '—' }}</td>
                        <td>
                            <a href="{{ route('orders.show', $order) }}" class="btn btn-sm btn-outline-primary">Sipariş</a>
                        </td>
                    </tr>
                @endforeach
            </x-table>
        @endif
    </div>
@endsection
