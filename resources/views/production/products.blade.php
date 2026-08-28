@extends('layouts.app')

@section('title', 'Üretim ürünleri')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h1 class="h3 mb-1">Ürünler</h1>
            <p class="eticart-muted mb-0">Görsel, barkod ve stok kontrolü. Düzenleme veya senkron bu ekranda yoktur.</p>
        </div>
    </div>

    <div class="eticart-card p-3 mb-3">
        <form method="GET" action="{{ route('production.products.index') }}" class="row g-2 align-items-end">
            <div class="col-12 col-md-5">
                <label class="form-label">Ara</label>
                <input type="search" name="q" value="{{ $search }}" class="form-control" placeholder="Başlık, SKU, barkod">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label">Stok</label>
                <select name="stock" class="form-select">
                    <option value="">Tümü</option>
                    <option value="nonzero" @selected($stockFilter === 'nonzero')>Stokta var</option>
                    <option value="low" @selected($stockFilter === 'low')>Tükenmek üzere (≤ {{ $lowStockThreshold }})</option>
                    <option value="zero" @selected($stockFilter === 'zero')>Stok yok</option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <button class="btn btn-outline-primary w-100" type="submit">Filtrele</button>
            </div>
        </form>
    </div>

    @if ($products->isEmpty())
        <x-empty-state title="Ürün bulunamadı" message="Filtreye uyan ürün yok." icon="bi-box" />
    @else
        <x-table :headers="['Görsel', 'Ürün', 'SKU', 'Barkod', 'Stok', '']">
            @foreach ($products as $product)
                @php
                    $stock = (int) $product->stock;
                    $stockClass = $stock <= 0 ? 'text-danger' : ($stock <= $lowStockThreshold ? 'text-warning' : '');
                @endphp
                <tr>
                    <td style="width: 56px;">
                        <a href="{{ route('production.products.show', $product) }}">
                            <x-product-list-thumb :url="$product->primaryImageUrl()" :alt="$product->title" />
                        </a>
                    </td>
                    <td>
                        <a href="{{ route('production.products.show', $product) }}" class="fw-semibold text-decoration-none">
                            {{ $product->title }}
                        </a>
                    </td>
                    <td>{{ $product->sku ?: '-' }}</td>
                    <td class="font-monospace">{{ $product->barcode ?: '-' }}</td>
                    <td class="fw-semibold {{ $stockClass }}">{{ $stock }}</td>
                    <td class="text-end">
                        <a href="{{ route('production.products.show', $product) }}" class="btn btn-sm btn-outline-primary">Detay</a>
                    </td>
                </tr>
            @endforeach
        </x-table>
        <div class="mt-3">{{ $products->links() }}</div>
    @endif
@endsection
