@extends('layouts.app')

@section('title', 'Müşteriler')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h1 class="h3 mb-1">Müşteriler</h1>
            <p class="eticart-muted mb-0">
                Shopify müşteri kayıtları ve siparişlerden derlenen kontaklar.
            </p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            @if (! $shopifyConfigured)
                <span class="badge text-bg-warning align-self-center">Shopify ayarları eksik</span>
            @endif
            <form method="POST" action="{{ route('customers.sync') }}" class="d-inline">
                @csrf
                <input type="hidden" name="source" value="orders">
                <button type="submit" class="btn btn-outline-secondary">Siparişlerden Derle</button>
            </form>
            <form method="POST" action="{{ route('customers.sync') }}" class="d-inline">
                @csrf
                <input type="hidden" name="source" value="all">
                <button type="submit" class="btn btn-primary" @disabled(! $shopifyConfigured)>
                    <i class="bi bi-cloud-download me-1"></i> Shopify’dan Çek
                </button>
            </form>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-md-4">
            <div class="eticart-card p-3">
                <div class="small eticart-muted">Toplam müşteri</div>
                <div class="fs-4 fw-semibold">{{ $counts['all'] }}</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="eticart-card p-3">
                <div class="small eticart-muted">E-postası olan</div>
                <div class="fs-4 fw-semibold">{{ $counts['with_email'] }}</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="eticart-card p-3">
                <div class="small eticart-muted">Siparişi olan</div>
                <div class="fs-4 fw-semibold">{{ $counts['with_orders'] }}</div>
            </div>
        </div>
    </div>

    <div class="alert alert-info small">
        <strong>Shopify CLI / Partner uygulaması şart değil.</strong>
        Bu proje mağazanın <em>Admin API</em> access token’ını kullanır (Ayarlar → Shopify).
        Müşteri adı/e-posta/telefon için custom app’te
        <code>read_customers</code> + <code>read_orders</code> ve Protected Customer Data Level 2 gerekir.
        Partner Dashboard’daki “npm init @shopify/app” yalnızca yeni uygulama iskeleti içindir; uzaktan otomatik app üretilmez.
    </div>

    <div class="eticart-card p-3 mb-3">
        <form method="GET" action="{{ route('customers.index') }}" class="row g-2 align-items-end">
            <div class="col-md-6">
                <label class="form-label">Ara</label>
                <input type="search" name="q" value="{{ $search }}" class="form-control" placeholder="Ad, e-posta, telefon, Shopify ID">
            </div>
            <div class="col-md-4">
                <label class="form-label">Şehir</label>
                <input type="text" name="city" value="{{ $city }}" class="form-control" placeholder="İl / ilçe">
            </div>
            <div class="col-md-2">
                <button class="btn btn-outline-primary w-100" type="submit">Filtrele</button>
            </div>
        </form>
    </div>

    @if ($customers->isEmpty())
        <x-empty-state
            title="Müşteri bulunamadı"
            message="Shopify’dan çekin veya önce sipariş senkronize edip “Siparişlerden Derle”yi kullanın."
            icon="bi-people"
        />
    @else
        <x-table :headers="['Müşteri', 'E-posta', 'Telefon', 'Şehir', 'Sipariş', 'Harcama', 'Son sipariş', '']">
            @foreach ($customers as $customer)
                <tr>
                    <td>
                        <a href="{{ route('customers.show', $customer) }}" class="fw-semibold text-decoration-none">
                            {{ $customer->displayName() }}
                        </a>
                        @if ($customer->shopify_customer_id)
                            <div class="small eticart-muted">#{{ $customer->shopify_customer_id }}</div>
                        @endif
                    </td>
                    <td>{{ $customer->email ?: '—' }}</td>
                    <td>{{ $customer->phone ?: '—' }}</td>
                    <td>{{ $customer->city ?: '—' }}</td>
                    <td>{{ $customer->orders_count }}</td>
                    <td>₺{{ number_format((float) $customer->total_spent, 2) }}</td>
                    <td>{{ optional($customer->last_order_at)->format('d.m.Y H:i') ?: '—' }}</td>
                    <td>
                        <a href="{{ route('customers.show', $customer) }}" class="btn btn-sm btn-outline-primary">Detay</a>
                    </td>
                </tr>
            @endforeach
        </x-table>

        <div class="mt-3">
            {{ $customers->links() }}
        </div>
    @endif
@endsection
