@extends('layouts.app')

@section('title', 'Ürünler')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h1 class="h3 mb-1">Ürünler</h1>
            <p class="eticart-muted mb-0">
                Tek kaynak: UyumSoft. Başlık, fiyat, stok ve varyantlar periyodik çekilir; farklar Shopify’a eşitlenir.
            </p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            @if (! $uyumConfigured)
                <span class="badge text-bg-warning align-self-center">UyumSoft ayarları eksik</span>
            @endif
            <form method="POST" action="{{ route('products.sync') }}" class="d-inline">
                @csrf
                <input type="hidden" name="type" value="products">
                <button type="submit" class="btn btn-primary" @disabled(! $uyumConfigured)>
                    <i class="bi bi-cloud-download me-1"></i> UyumSoft’tan Çek
                </button>
            </form>
        </div>
    </div>

    @if (session('sync_results'))
        <div class="eticart-card p-3 mb-3">
            <h2 class="h6 mb-2">Aktarım Sonuçları</h2>
            <x-table :headers="['Ürün', 'Durum', 'Detay']">
                @foreach (session('sync_results') as $result)
                    <tr>
                        <td>{{ $result['title'] ?? ('#'.$result['id']) }}</td>
                        <td>
                            @php
                                $type = match ($result['status'] ?? '') {
                                    'success' => 'success',
                                    'skipped' => 'warning',
                                    default => 'danger',
                                };
                            @endphp
                            <x-badge :type="$type">{{ $result['status'] ?? '-' }}</x-badge>
                        </td>
                        <td>{{ $result['shopify_product_id'] ?? ($result['message'] ?? '-') }}</td>
                    </tr>
                @endforeach
            </x-table>
        </div>
    @endif

    <ul class="nav nav-tabs mb-3">
        @foreach ([
            'all' => ['label' => 'Tümü', 'count' => $counts['all']],
            'pending' => ['label' => 'Aktarılacak', 'count' => $counts['pending']],
            'synced' => ['label' => 'Shopify Eşit', 'count' => $counts['synced']],
            'passive' => ['label' => 'Pasif', 'count' => $counts['passive']],
        ] as $key => $meta)
            <li class="nav-item">
                <a class="nav-link {{ $tab === $key ? 'active' : '' }}"
                   href="{{ route('products.index', array_filter(['tab' => $key] + ($listQuery ?? []))) }}">
                    {{ $meta['label'] }} <span class="badge text-bg-light">{{ $meta['count'] }}</span>
                </a>
            </li>
        @endforeach
    </ul>

    <div class="eticart-card mb-3">
        <div class="p-3 border-bottom">
            <button class="btn btn-link text-decoration-none p-0 fw-semibold" type="button"
                    data-bs-toggle="collapse" data-bs-target="#productToolsPanel" aria-expanded="true" aria-controls="productToolsPanel">
                <i class="bi bi-sliders me-1"></i> Detaylı filtreler ve toplu işlemler
            </button>
        </div>
        <div class="collapse show" id="productToolsPanel">
            <div class="p-3 border-bottom">
                <form method="GET" action="{{ route('products.index') }}" class="row g-2 align-items-end">
                    <input type="hidden" name="tab" value="{{ $tab }}">
                    <div class="col-lg-3 col-md-6">
                        <label class="form-label">Ara</label>
                        <input type="search" name="q" value="{{ $search }}" class="form-control" placeholder="Başlık, SKU, barkod, ID">
                    </div>
                    <div class="col-lg-2 col-md-3">
                        <label class="form-label">Aktiflik</label>
                        <select name="status" class="form-select">
                            <option value="">Tümü</option>
                            <option value="active" @selected($status === 'active')>Aktif</option>
                            <option value="passive" @selected($status === 'passive')>Pasif</option>
                        </select>
                    </div>
                    <div class="col-lg-2 col-md-3">
                        <label class="form-label">Shopify</label>
                        <select name="shopify_status" class="form-select">
                            <option value="">Tümü</option>
                            <option value="synced" @selected($shopifyStatus === 'synced')>Eşitlenmiş</option>
                            <option value="pending" @selected($shopifyStatus === 'pending')>Bekleyen</option>
                        </select>
                    </div>
                    <div class="col-lg-2 col-md-3">
                        <label class="form-label">Stok</label>
                        <select name="stock" class="form-select">
                            <option value="">Tümü</option>
                            <option value="nonzero" @selected($stockFilter === 'nonzero')>Stokta var</option>
                            <option value="zero" @selected($stockFilter === 'zero')>Stok yok</option>
                        </select>
                    </div>
                    <div class="col-lg-1 col-md-3">
                        <label class="form-label">Min ₺</label>
                        <input type="number" step="0.01" min="0" name="price_min" value="{{ $priceMin }}" class="form-control">
                    </div>
                    <div class="col-lg-1 col-md-3">
                        <label class="form-label">Max ₺</label>
                        <input type="number" step="0.01" min="0" name="price_max" value="{{ $priceMax }}" class="form-control">
                    </div>
                    <div class="col-lg-1 col-md-3">
                        <label class="form-label">Sayfa başı</label>
                        <select name="per_page" class="form-select">
                            @foreach ($perPageOptions ?? [10, 20, 50, 100] as $option)
                                <option value="{{ $option }}" @selected((int) ($perPage ?? 20) === (int) $option)>{{ $option }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-lg-1 col-md-3">
                        <button class="btn btn-outline-primary w-100" type="submit">Filtrele</button>
                    </div>
                </form>

                <div class="d-flex flex-wrap gap-2 mt-3">
                    <form method="POST" action="{{ route('products.bulk') }}" class="d-inline">
                        @csrf
                        <input type="hidden" name="action" value="reconcile">
                        <button type="submit" class="btn btn-sm btn-primary" @disabled(! $uyumConfigured || ! $shopifyConfigured)>
                            <i class="bi bi-arrow-repeat me-1"></i> Güncellemeleri kontrol et
                        </button>
                    </form>
                    <form method="POST" action="{{ route('products.bulk') }}" class="d-inline">
                        @csrf
                        <input type="hidden" name="action" value="export_excel">
                        <button type="submit" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-file-earmark-excel me-1"></i> Excel dışa aktar
                        </button>
                    </form>
                    <form method="POST" action="{{ route('products.sync') }}" class="d-inline">
                        @csrf
                        <input type="hidden" name="type" value="stock">
                        <button type="submit" class="btn btn-sm btn-outline-primary" @disabled(! $uyumConfigured)>
                            <i class="bi bi-box-seam me-1"></i> Stok yenile
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    @include('products.partials.uyumsoft-table', [
        'products' => $products,
        'shopifyConfigured' => $shopifyConfigured,
        'uyumConfigured' => $uyumConfigured,
        'syncOptions' => $syncOptions,
        'tab' => $tab,
        'perPage' => $perPage ?? 20,
        'perPageOptions' => $perPageOptions ?? [10, 20, 50, 100],
        'listQuery' => $listQuery ?? [],
    ])
@endsection
