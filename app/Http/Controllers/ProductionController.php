<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\PackingInProgressException;
use App\Models\ShopifyOrder;
use App\Models\UyumSoftProduct;
use App\Services\OrderPackingService;
use App\Services\ProductImageCacheService;
use App\Services\ProductionFloorService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProductionController extends Controller
{
    public function __construct(
        private readonly ProductionFloorService $floor,
        private readonly ProductImageCacheService $imageCache,
        private readonly OrderPackingService $packing
    ) {
    }

    public function dashboard(): View
    {
        $this->guardStaff();

        return view('production.dashboard', [
            'stats' => $this->floor->todayStats(request()->user()),
            'awaitingOrders' => $this->floor->awaitingOrders(15),
            'lowStockGroups' => $this->floor->lowStockGroups(),
            'lowStockThreshold' => ProductionFloorService::LOW_STOCK_THRESHOLD,
            'breadcrumbs' => [
                ['label' => 'Üretim'],
            ],
        ]);
    }

    public function orders(Request $request): View
    {
        $this->guardStaff();

        $filter = $request->string('packed')->toString();
        $search = trim($request->string('q')->toString());

        $query = ShopifyOrder::query()->with('items')->latest();

        if ($filter === '1') {
            $query->whereNotNull('packed_at');
        } elseif ($filter === '0') {
            $query->awaitingPacking();
        } else {
            $filter = 'all';
        }

        if ($search !== '') {
            $query->where('order_number', 'like', '%'.$search.'%');
        }

        return view('production.orders', [
            'orders' => $query->paginate(20)->withQueryString(),
            'filter' => $filter,
            'search' => $search,
            'breadcrumbs' => [
                ['label' => 'Üretim', 'url' => route('dashboard')],
                ['label' => 'Hazırlama'],
            ],
        ]);
    }

    public function order(Request $request, ShopifyOrder $order): View
    {
        $this->guardStaff();
        $order->load('items');

        $user = $request->user();
        if (! $order->isPacked() && $user?->isPackingStaff()) {
            try {
                $order = $this->packing->claim($order, $user);
                $order->load('items');
            } catch (PackingInProgressException $e) {
                session()->now('warning', $e->getMessage());
            }
        }

        return view('production.order-show', [
            'order' => $order,
            'lines' => $this->floor->decorateItems($order->items),
            'breadcrumbs' => [
                ['label' => 'Üretim', 'url' => route('dashboard')],
                ['label' => 'Hazırlama', 'url' => route('production.orders.index')],
                ['label' => $order->order_number],
            ],
        ]);
    }

    public function products(Request $request): View
    {
        $this->guardStaff();

        $search = trim($request->string('q')->toString());
        $stockFilter = $request->string('stock')->toString();

        $query = UyumSoftProduct::query()
            ->with('shopifyProduct')
            ->where('is_active', true)
            ->orderBy('title');

        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder->where('title', 'like', '%'.$search.'%')
                    ->orWhere('sku', 'like', '%'.$search.'%')
                    ->orWhere('barcode', 'like', '%'.$search.'%');
            });
        }

        if ($stockFilter === 'zero') {
            $query->where('stock', '<=', 0);
        } elseif ($stockFilter === 'low') {
            $query->where('stock', '>', 0)->where('stock', '<=', ProductionFloorService::LOW_STOCK_THRESHOLD);
        } elseif ($stockFilter === 'nonzero') {
            $query->where('stock', '>', 0);
        }

        return view('production.products', [
            'products' => $query->paginate(24)->withQueryString(),
            'search' => $search,
            'stockFilter' => $stockFilter,
            'lowStockThreshold' => ProductionFloorService::LOW_STOCK_THRESHOLD,
            'breadcrumbs' => [
                ['label' => 'Üretim', 'url' => route('dashboard')],
                ['label' => 'Ürünler'],
            ],
        ]);
    }

    public function product(UyumSoftProduct $product): View
    {
        $this->guardStaff();
        $product->load('shopifyProduct');
        $localized = $this->imageCache->localizeUyumSoft($product);

        return view('production.product-show', [
            'product' => $product,
            'variants' => $localized['variants'],
            'images' => $localized['images'],
            'breadcrumbs' => [
                ['label' => 'Üretim', 'url' => route('dashboard')],
                ['label' => 'Ürünler', 'url' => route('production.products.index')],
                ['label' => $product->title],
            ],
        ]);
    }

    private function guardStaff(): void
    {
        if (! request()->user()?->isPackingStaff() && ! request()->user()?->hasRole('admin')) {
            abort(403, 'Bu sayfa üretim personeli içindir.');
        }
    }
}
