<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\ShopifyException;
use App\Exceptions\UyumSoftException;
use App\Jobs\PullShopifyProducts;
use App\Jobs\SyncStock;
use App\Jobs\SyncUyumSoftProducts;
use App\Models\ShopifyProduct;
use App\Models\SyncActivity;
use App\Models\UyumSoftProduct;
use App\Services\ProductSyncService;
use App\Services\ShopifyService;
use App\Services\SyncActivityTracker;
use App\Services\UyumSoftService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductController extends Controller
{
    public function __construct(
        private readonly UyumSoftService $uyumSoftService,
        private readonly ShopifyService $shopifyService,
        private readonly ProductSyncService $productSyncService,
        private readonly SyncActivityTracker $activityTracker
    ) {
    }

    /**
     * Product list (UyumSoft kaynaklı tüm ürünler).
     */
    public function index(Request $request): View
    {
        $tab = $request->string('tab', 'all')->toString();
        $search = $request->string('q')->toString();
        $status = $request->string('status')->toString();
        $shopifyStatus = $request->string('shopify_status')->toString();
        $stockFilter = $request->string('stock')->toString();
        $priceMin = $request->input('price_min');
        $priceMax = $request->input('price_max');

        $query = UyumSoftProduct::query()->with('shopifyProduct')->latest();

        if ($tab === 'synced') {
            $query->where('synced_to_shopify', true);
        } elseif ($tab === 'pending') {
            $query->where('synced_to_shopify', false)->where('is_active', true);
        } elseif ($tab === 'passive') {
            $query->where('is_active', false);
        }

        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'passive') {
            $query->where('is_active', false);
        }

        if ($shopifyStatus === 'synced') {
            $query->where('synced_to_shopify', true);
        } elseif ($shopifyStatus === 'pending') {
            $query->where('synced_to_shopify', false);
        }

        if ($stockFilter === 'zero') {
            $query->where('stock', '<=', 0);
        } elseif ($stockFilter === 'nonzero') {
            $query->where('stock', '>', 0);
        }

        if ($priceMin !== null && $priceMin !== '') {
            $query->where('original_price', '>=', (float) $priceMin);
        }
        if ($priceMax !== null && $priceMax !== '') {
            $query->where('original_price', '<=', (float) $priceMax);
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%")
                    ->orWhere('uyumsoft_id', 'like', "%{$search}%");
            });
        }

        return view('products.index', [
            'tab' => in_array($tab, ['all', 'pending', 'synced', 'passive'], true) ? $tab : 'all',
            'search' => $search,
            'status' => $status,
            'shopifyStatus' => $shopifyStatus,
            'stockFilter' => $stockFilter,
            'priceMin' => $priceMin,
            'priceMax' => $priceMax,
            'products' => $query->paginate(20)->withQueryString(),
            'counts' => [
                'all' => UyumSoftProduct::query()->count(),
                'synced' => UyumSoftProduct::query()->where('synced_to_shopify', true)->count(),
                'pending' => UyumSoftProduct::query()->where('synced_to_shopify', false)->where('is_active', true)->count(),
                'passive' => UyumSoftProduct::query()->where('is_active', false)->count(),
            ],
            'uyumConfigured' => $this->uyumSoftService->isConfigured(),
            'shopifyConfigured' => $this->shopifyService->isConfigured(),
            'syncOptions' => [
                ProductSyncService::OPTION_ALL => 'Tümü (bilgi + görsel + stok + fiyat)',
                ProductSyncService::OPTION_INFO => 'Sadece ürün bilgileri',
                ProductSyncService::OPTION_IMAGES => 'Sadece görseller',
                ProductSyncService::OPTION_STOCK => 'Sadece stok',
                ProductSyncService::OPTION_PRICE => 'Sadece fiyat',
            ],
            'breadcrumbs' => [
                ['label' => 'Dashboard', 'url' => route('dashboard')],
                ['label' => 'Ürünler'],
            ],
        ]);
    }

    public function uyumSoftList(Request $request): View
    {
        $request->merge(['tab' => 'all']);

        return $this->index($request);
    }

    public function shopifyList(Request $request): RedirectResponse
    {
        return redirect()->route('products.index', ['tab' => 'synced']);
    }

    /**
     * Bulk actions: Shopify push / activate / deactivate.
     */
    public function bulk(Request $request): RedirectResponse|StreamedResponse
    {
        $validated = $request->validate([
            'product_ids' => ['nullable', 'array'],
            'product_ids.*' => ['integer', 'exists:uyumsoft_products,id'],
            'action' => ['required', 'in:push_shopify,activate,deactivate,reconcile,export_excel'],
            'sync_options' => ['nullable', 'array'],
            'sync_options.*' => ['string', 'in:all,info,images,stock,price'],
        ]);

        $ids = $validated['product_ids'] ?? [];

        if ($validated['action'] === 'export_excel') {
            return $this->exportExcel($request, $ids);
        }

        if ($validated['action'] === 'reconcile') {
            $activity = $this->activityTracker->start(
                'product_reconcile',
                'UyumSoft → Shopify güncelleme kontrolü',
                null,
                ['source' => 'products.bulk']
            );

            $activityId = $activity->id;
            $uuid = $activity->uuid;

            dispatch(function () use ($activityId): void {
                $tracker = app(SyncActivityTracker::class);
                $activity = SyncActivity::query()->find($activityId);
                if (! $activity) {
                    return;
                }
                $tracker->bind($activity);
                try {
                    app(ProductSyncService::class)->syncAllFromUyumSoftAndReconcile(50, true);
                } catch (\Throwable $e) {
                    report($e);
                    $tracker->fail($e->getMessage(), $e);
                }
            })->afterResponse();

            return back()
                ->with('success', 'Güncelleme kontrolü arka planda başladı. Sağ alttan takip edebilirsiniz.')
                ->with('sync_activity_uuid', $uuid);
        }

        if ($ids === []) {
            return back()->with('error', 'Bu işlem için en az bir ürün seçin.');
        }

        if ($validated['action'] === 'activate') {
            UyumSoftProduct::query()->whereIn('id', $ids)->update(['is_active' => true]);
            $activity = $this->activityTracker->start(
                'product_status_push',
                'Shopify aktif durumu ('.count($ids).' ürün)',
                count($ids)
            );
            $this->activityTracker->markRunning('Shopify active durumuna yazılıyor…');
            try {
                $push = $this->productSyncService->syncActiveStatusToShopify($ids);
                $this->activityTracker->complete($push['message'], (int) $push['synced'], (int) $push['errors']);
            } catch (\Throwable $e) {
                $this->activityTracker->fail($e->getMessage(), $e);
                $push = ['message' => $e->getMessage()];
            }

            return back()->with('success', count($ids).' ürün aktifleştirildi. '.$push['message'])
                ->with('sync_activity_uuid', $activity->uuid);
        }

        if ($validated['action'] === 'deactivate') {
            UyumSoftProduct::query()->whereIn('id', $ids)->update(['is_active' => false]);
            $activity = $this->activityTracker->start(
                'product_status_push',
                'Shopify pasif durumu ('.count($ids).' ürün)',
                count($ids)
            );
            $this->activityTracker->markRunning('Shopify draft durumuna yazılıyor…');
            try {
                $push = $this->productSyncService->syncActiveStatusToShopify($ids);
                $this->activityTracker->complete($push['message'], (int) $push['synced'], (int) $push['errors']);
            } catch (\Throwable $e) {
                $this->activityTracker->fail($e->getMessage(), $e);
                $push = ['message' => $e->getMessage()];
            }

            return back()->with(
                'success',
                count($ids).' ürün pasife alındı. '.$push['message']
            )->with('sync_activity_uuid', $activity->uuid);
        }

        $options = $validated['sync_options'] ?? [ProductSyncService::OPTION_ALL];

        $activity = $this->activityTracker->start(
            'shopify_push',
            'Shopify toplu aktarım ('.count($ids).' ürün)',
            count($ids),
            ['product_ids' => $ids, 'options' => $options]
        );
        $activityId = $activity->id;
        $uuid = $activity->uuid;
        $userId = Auth::id();

        dispatch(function () use ($activityId, $ids, $options, $userId): void {
            $tracker = app(SyncActivityTracker::class);
            $activity = SyncActivity::query()->find($activityId);
            if (! $activity) {
                return;
            }
            $tracker->bind($activity);
            $tracker->markRunning('Shopify aktarımı başladı…');
            try {
                $result = app(ProductSyncService::class)->syncToShopify($ids, $options);
                $tracker->complete(
                    $result['message'],
                    (int) ($result['synced'] ?? 0),
                    (int) ($result['errors'] ?? 0),
                    ['skipped' => $result['skipped'] ?? 0, 'user_id' => $userId]
                );
            } catch (\Throwable $e) {
                report($e);
                $tracker->fail($e->getMessage(), $e);
            }
        })->afterResponse();

        return redirect()
            ->route('products.index', ['tab' => 'all'])
            ->with('success', 'Shopify aktarımı arka planda başladı. Sağ alttan takip edebilirsiniz.')
            ->with('sync_activity_uuid', $uuid);
    }

    /**
     * Sync selected products page / action (legacy + preview).
     */
    public function syncToShopify(Request $request): View|RedirectResponse
    {
        if ($request->isMethod('post')) {
            $validated = $request->validate([
                'product_ids' => ['required', 'array', 'min:1'],
                'product_ids.*' => ['integer', 'exists:uyumsoft_products,id'],
                'sync_options' => ['nullable', 'array'],
                'sync_options.*' => ['string', 'in:all,info,images,stock,price'],
            ]);

            try {
                $result = $this->productSyncService->syncToShopify(
                    $validated['product_ids'],
                    $validated['sync_options'] ?? [ProductSyncService::OPTION_ALL]
                );

                return redirect()
                    ->route('products.sync-to-shopify')
                    ->with('success', $result['message'])
                    ->with('sync_results', $result['results']);
            } catch (ShopifyException $e) {
                return redirect()
                    ->route('products.sync-to-shopify')
                    ->with('error', $e->getMessage());
            } catch (\Throwable $e) {
                report($e);

                return redirect()
                    ->route('products.sync-to-shopify')
                    ->with('error', 'Shopify senkronizasyonu sırasında hata oluştu.');
            }
        }

        $selectedIds = array_filter(array_map('intval', (array) $request->input('product_ids', [])));
        $products = $selectedIds
            ? $this->productSyncService->previewForShopify($selectedIds)
            : UyumSoftProduct::query()->where('is_active', true)->where('synced_to_shopify', false)->latest()->limit(50)->get()->map(fn ($p) => [
                'id' => $p->id,
                'title' => $p->title,
                'sku' => $p->sku,
                'price' => $p->original_price,
                'stock' => $p->stock,
                'is_active' => $p->is_active,
                'already_synced' => false,
            ]);

        return view('products.sync', [
            'products' => $products,
            'shopifyConfigured' => $this->shopifyService->isConfigured(),
            'syncResults' => session('sync_results', []),
            'syncOptions' => [
                ProductSyncService::OPTION_ALL => 'Tümü',
                ProductSyncService::OPTION_INFO => 'Sadece bilgi',
                ProductSyncService::OPTION_IMAGES => 'Sadece görsel',
                ProductSyncService::OPTION_STOCK => 'Sadece stok',
                ProductSyncService::OPTION_PRICE => 'Sadece fiyat',
            ],
            'breadcrumbs' => [
                ['label' => 'Dashboard', 'url' => route('dashboard')],
                ['label' => 'Ürünler', 'url' => route('products.index')],
                ['label' => 'Shopify Sync'],
            ],
        ]);
    }

    /**
     * Show product detail.
     */
    public function show(UyumSoftProduct $product): View
    {
        $product->load('shopifyProduct');

        return view('products.show', [
            'product' => $product,
            'variants' => $product->variantRows(),
            'attributeGroups' => $product->attributeGroups(),
            'images' => $product->imageUrls(),
            'shopifyConfigured' => $this->shopifyService->isConfigured(),
            'syncResults' => session('sync_results', []),
            'breadcrumbs' => [
                ['label' => 'Dashboard', 'url' => route('dashboard')],
                ['label' => 'Ürünler', 'url' => route('products.index')],
                ['label' => $product->title],
            ],
        ]);
    }

    /**
     * Edit product form (redirects to show with edit mode preference — keep dedicated edit).
     */
    public function edit(UyumSoftProduct $product): View
    {
        return view('products.edit', [
            'product' => $product,
            'imagesText' => implode("\n", $product->imageUrls()),
            'breadcrumbs' => [
                ['label' => 'Dashboard', 'url' => route('dashboard')],
                ['label' => 'Ürünler', 'url' => route('products.index')],
                ['label' => $product->title, 'url' => route('products.show', $product)],
                ['label' => 'Düzenle'],
            ],
        ]);
    }

    /**
     * Update local product then optionally push to Shopify.
     */
    public function update(Request $request, UyumSoftProduct $product): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:100'],
            'barcode' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:10000'],
            'original_price' => ['required', 'numeric', 'min:0'],
            'stock' => ['required', 'integer', 'min:0'],
            'images_text' => ['nullable', 'string', 'max:20000'],
            'image_files' => ['nullable', 'array', 'max:10'],
            'image_files.*' => ['image', 'max:5120'],
            'is_active' => ['nullable', 'boolean'],
            'push_to_shopify' => ['nullable', 'boolean'],
            'sync_options' => ['nullable', 'array'],
            'sync_options.*' => ['string', 'in:all,info,images,stock,price'],
        ]);

        $images = [];
        foreach (preg_split('/\r\n|\r|\n/', (string) ($validated['images_text'] ?? '')) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '' && filter_var($line, FILTER_VALIDATE_URL)) {
                $images[] = $line;
            }
        }

        $hasNewImages = false;
        if ($request->hasFile('image_files')) {
            foreach ($request->file('image_files') as $file) {
                if (! $file) {
                    continue;
                }
                $path = $file->store('products/'.$product->id, 'public');
                $images[] = url(Storage::disk('public')->url($path));
                $hasNewImages = true;
            }
        }

        $images = array_values(array_unique($images));

        $product->update([
            'title' => $validated['title'],
            'sku' => $validated['sku'] ?? null,
            'barcode' => $validated['barcode'] ?? null,
            'description' => $validated['description'] ?? null,
            'original_price' => $validated['original_price'],
            'stock' => $validated['stock'],
            'images' => $images,
            'is_active' => $request->boolean('is_active'),
        ]);

        $message = 'Ürün güncellendi.';

        if ($request->boolean('push_to_shopify')) {
            if (! $product->is_active) {
                return redirect()
                    ->route('products.show', $product)
                    ->with('warning', $message.' Pasif ürün Shopify’a gönderilmedi.');
            }

            try {
                $options = $validated['sync_options'] ?? [ProductSyncService::OPTION_ALL];
                if ($hasNewImages
                    && ! in_array(ProductSyncService::OPTION_ALL, $options, true)
                    && ! in_array(ProductSyncService::OPTION_IMAGES, $options, true)) {
                    $options[] = ProductSyncService::OPTION_IMAGES;
                }

                $result = $this->productSyncService->syncToShopify([$product->id], $options);

                return redirect()
                    ->route('products.show', $product)
                    ->with('success', $message.' '.$result['message'])
                    ->with('sync_results', $result['results']);
            } catch (ShopifyException $e) {
                return redirect()
                    ->route('products.show', $product)
                    ->with('error', $message.' Shopify eşitleme hatası: '.$e->getMessage());
            }
        }

        return redirect()
            ->route('products.show', $product)
            ->with('success', $message);
    }

    /**
     * Toggle active/passive.
     */
    public function toggleActive(UyumSoftProduct $product): RedirectResponse
    {
        $product->update(['is_active' => ! $product->is_active]);
        $label = $product->is_active ? 'aktifleştirildi' : 'pasife alındı';

        $message = "Ürün {$label}.";
        try {
            $this->activityTracker->start(
                'product_status_push',
                ($product->sku ?: $product->title).' Shopify durumu',
                1,
                ['product_id' => $product->id]
            );
            $this->activityTracker->markRunning('Shopify product status güncelleniyor…');
            $push = $this->productSyncService->pushShopifyActiveStatus($product->fresh());
            $this->activityTracker->complete($push['message'], $push['skipped'] ? 0 : 1, $push['success'] ? 0 : 1);
            $message .= ' '.$push['message'];
        } catch (\Throwable $e) {
            $this->activityTracker->fail($e->getMessage(), $e);
            $message .= ' Shopify durum güncellemesi başarısız: '.$e->getMessage();
        }

        return back()->with('success', $message);
    }

    /**
     * Push single product to Shopify.
     */
    public function pushShopify(Request $request, UyumSoftProduct $product): RedirectResponse
    {
        $validated = $request->validate([
            'sync_options' => ['nullable', 'array'],
            'sync_options.*' => ['string', 'in:all,info,images,stock,price'],
        ]);

        try {
            $result = $this->productSyncService->syncToShopify(
                [$product->id],
                $validated['sync_options'] ?? [ProductSyncService::OPTION_ALL]
            );

            return redirect()
                ->route('products.show', $product)
                ->with('success', $result['message'])
                ->with('sync_results', $result['results']);
        } catch (ShopifyException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Shopify mirror product detail page.
     */
    public function showShopifyMirror(ShopifyProduct $shopifyProduct): View
    {
        $shopifyProduct->load('uyumSoftProduct');

        return view('products.shopify-show', [
            'product' => $shopifyProduct,
            'variants' => $shopifyProduct->variantRows(),
            'images' => $shopifyProduct->imageRows(),
            'adminUrl' => $this->shopifyService->adminProductUrl($shopifyProduct->shopify_product_id),
            'breadcrumbs' => [
                ['label' => 'Dashboard', 'url' => route('dashboard')],
                ['label' => 'Ürünler', 'url' => route('products.index')],
                ['label' => 'Shopify Eşit', 'url' => route('products.index', ['tab' => 'synced'])],
                ['label' => $shopifyProduct->title],
            ],
        ]);
    }

    /**
     * Pull products from Shopify into local mirror list.
     */
    public function pullFromShopify(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'queue' => ['nullable', 'boolean'],
        ]);

        try {
            if ($request->boolean('queue')) {
                PullShopifyProducts::dispatch();

                return redirect()
                    ->route('products.index', ['tab' => 'synced'])
                    ->with('success', 'Shopify ürün çekme işlemi kuyruğa alındı.');
            }

            $result = $this->productSyncService->pullFromShopify();

            return redirect()
                ->route('products.index', ['tab' => 'synced'])
                ->with('success', $result['message']);
        } catch (ShopifyException $e) {
            return redirect()
                ->route('products.index')
                ->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            report($e);

            return redirect()
                ->route('products.index')
                ->with('error', 'Shopify ürünleri çekilirken beklenmeyen bir hata oluştu.');
        }
    }

    /**
     * Manual sync trigger from UyumSoft (runs after HTTP response so the monitor can poll).
     */
    public function sync(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:250'],
            'offset' => ['nullable', 'integer', 'min:0'],
            'type' => ['nullable', 'in:products,stock,reconcile'],
            'queue' => ['nullable', 'boolean'],
        ]);

        $type = $validated['type'] ?? 'products';
        $limit = (int) ($validated['limit'] ?? 50);
        $offset = (int) ($validated['offset'] ?? 0);

        $titles = [
            'stock' => 'Stok senkronizasyonu',
            'reconcile' => 'UyumSoft → Shopify eşitleme',
            'products' => 'UyumSoft ürün çekimi',
        ];

        $activity = $this->activityTracker->start(
            match ($type) {
                'stock' => 'stock_sync',
                'reconcile' => 'product_reconcile',
                default => 'product_sync',
            },
            $titles[$type] ?? 'Ürün senkronizasyonu',
            null,
            ['limit' => $limit, 'offset' => $offset, 'type' => $type]
        );

        $activityId = $activity->id;
        $uuid = $activity->uuid;

        // Legacy queue flag: still supported, but prefer afterResponse for live progress without worker.
        if (! empty($validated['queue'])) {
            if ($type === 'stock') {
                SyncStock::dispatch();
            } else {
                SyncUyumSoftProducts::dispatch($limit, $offset, true, $type === 'reconcile');
            }

            return redirect()
                ->route('products.index')
                ->with('success', 'Senkronizasyon kuyruğa alındı. Sağ alttan takip edebilirsiniz.')
                ->with('sync_activity_uuid', $uuid);
        }

        dispatch(function () use ($activityId, $type, $limit, $offset): void {
            $tracker = app(SyncActivityTracker::class);
            $activity = SyncActivity::query()->find($activityId);
            if (! $activity) {
                return;
            }
            $tracker->bind($activity);

            try {
                $service = app(ProductSyncService::class);
                match ($type) {
                    'stock' => $service->syncStock(),
                    'reconcile' => $service->syncAllFromUyumSoftAndReconcile($limit, true),
                    default => $service->syncAllFromUyumSoftAndReconcile($limit, false),
                };
            } catch (\Throwable $e) {
                report($e);
                $tracker->fail($e->getMessage(), $e);
            }
        })->afterResponse();

        return redirect()
            ->route('products.index')
            ->with('success', 'İşlem arka planda başladı. Sağ alttan ilerlemeyi takip edebilirsiniz.')
            ->with('sync_activity_uuid', $uuid);
    }

    /**
     * Export products as CSV (Excel-compatible).
     *
     * @param  array<int, int>  $ids
     */
    private function exportExcel(Request $request, array $ids = []): StreamedResponse
    {
        $query = UyumSoftProduct::query()->with('shopifyProduct')->latest();
        if ($ids !== []) {
            $query->whereIn('id', $ids);
        }

        $filename = 'urunler_'.now()->format('Ymd_His').'.csv';

        return response()->streamDownload(function () use ($query): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }

            fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($out, [
                'ID', 'UyumSoft ID', 'Başlık', 'SKU', 'Barkod', 'Fiyat', 'Stok',
                'Varyant Sayısı', 'Aktif', 'Shopify Eşit', 'Shopify ID', 'Son Sync',
            ], ';');

            $query->chunk(200, function ($products) use ($out): void {
                foreach ($products as $product) {
                    fputcsv($out, [
                        $product->id,
                        $product->uyumsoft_id,
                        $product->title,
                        $product->sku,
                        $product->barcode,
                        $product->original_price,
                        $product->stock,
                        count($product->variantRows()),
                        $product->is_active ? 'Evet' : 'Hayır',
                        $product->synced_to_shopify ? 'Evet' : 'Hayır',
                        $product->shopify_id,
                        optional($product->last_sync)->format('d.m.Y H:i'),
                    ], ';');
                }
            });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
