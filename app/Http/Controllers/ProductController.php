<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\ShopifyException;
use App\Exceptions\UyumSoftException;
use App\Jobs\ProcessBulkProductAction;
use App\Jobs\PullShopifyProducts;
use App\Jobs\SyncStock;
use App\Jobs\SyncUyumSoftProducts;
use App\Models\ShopifyProduct;
use App\Models\UyumSoftProduct;
use App\Services\ProductSyncService;
use App\Services\ShopifyService;
use App\Services\SyncActivityTracker;
use App\Services\UyumSoftService;
use App\Support\ShopifyMetafieldFormatter;
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
        $perPageOptions = [10, 20, 50, 100];
        $perPage = (int) $request->integer('per_page', 20);
        if (! in_array($perPage, $perPageOptions, true)) {
            $perPage = 20;
        }

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
            'perPage' => $perPage,
            'perPageOptions' => $perPageOptions,
            'listQuery' => array_filter([
                'q' => $search !== '' ? $search : null,
                'status' => $status !== '' ? $status : null,
                'shopify_status' => $shopifyStatus !== '' ? $shopifyStatus : null,
                'stock' => $stockFilter !== '' ? $stockFilter : null,
                'price_min' => ($priceMin !== null && $priceMin !== '') ? $priceMin : null,
                'price_max' => ($priceMax !== null && $priceMax !== '') ? $priceMax : null,
                'per_page' => $perPage,
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
            'products' => $query->paginate($perPage)->withQueryString(),
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
                ['label' => 'Anasayfa', 'url' => route('dashboard')],
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
            'action' => ['required', 'in:push_shopify,pull_shopify,activate,deactivate,reconcile,export_excel'],
            'sync_options' => ['nullable', 'array'],
            'sync_options.*' => ['string', 'in:all,info,images,stock,price'],
        ]);

        $ids = $validated['product_ids'] ?? [];

        if ($validated['action'] === 'export_excel') {
            return $this->exportExcel($request, $ids);
        }

        if ($validated['action'] === 'reconcile') {
            $uuid = $this->queueBulkProductAction(
                'reconcile',
                'product_reconcile',
                'UyumSoft → Shopify güncelleme kontrolü',
                [],
                [],
                null,
                ['source' => 'products.bulk']
            );

            return back()
                ->with('success', 'Güncelleme kontrolü kuyruğa alındı. Sağ alttan takip edebilirsiniz.')
                ->with('sync_activity_uuid', $uuid);
        }

        if ($ids === []) {
            if ($validated['action'] === 'pull_shopify') {
                $ids = UyumSoftProduct::query()
                    ->where(function ($query): void {
                        $query->whereNotNull('shopify_id')
                            ->orWhere('synced_to_shopify', true);
                    })
                    ->pluck('id')
                    ->all();

                if ($ids === []) {
                    return back()->with('error', 'Shopify’dan çekilecek eşitlenmiş ürün yok.');
                }
            } else {
                return back()->with('error', 'Bu işlem için en az bir ürün seçin.');
            }
        }

        $ids = array_values(array_unique(array_map('intval', $ids)));

        if ($validated['action'] === 'pull_shopify') {
            $uuid = $this->queueBulkProductAction(
                'pull_shopify',
                'shopify_pull',
                'Shopify’dan güncelle ('.count($ids).' ürün)',
                $ids,
                [],
                count($ids),
                ['product_ids' => $ids]
            );

            return back()
                ->with('success', 'Shopify’dan güncelleme kuyruğa alındı. Sağ alttan takip edebilirsiniz.')
                ->with('sync_activity_uuid', $uuid);
        }

        if ($validated['action'] === 'activate') {
            UyumSoftProduct::query()->whereIn('id', $ids)->update(['is_active' => true]);
            $uuid = $this->queueBulkProductAction(
                'activate',
                'product_status_push',
                'Shopify aktif durumu ('.count($ids).' ürün)',
                $ids,
                [],
                count($ids)
            );

            return back()
                ->with('success', count($ids).' ürün aktifleştirildi. Shopify durumu kuyruğa alındı.')
                ->with('sync_activity_uuid', $uuid);
        }

        if ($validated['action'] === 'deactivate') {
            UyumSoftProduct::query()->whereIn('id', $ids)->update(['is_active' => false]);
            $uuid = $this->queueBulkProductAction(
                'deactivate',
                'product_status_push',
                'Shopify pasif durumu ('.count($ids).' ürün)',
                $ids,
                [],
                count($ids)
            );

            return back()
                ->with('success', count($ids).' ürün pasife alındı. Shopify durumu kuyruğa alındı.')
                ->with('sync_activity_uuid', $uuid);
        }

        $options = $validated['sync_options'] ?? [ProductSyncService::OPTION_ALL];
        $uuid = $this->queueBulkProductAction(
            'push_shopify',
            'shopify_push',
            'Shopify toplu aktarım ('.count($ids).' ürün)',
            $ids,
            $options,
            count($ids),
            ['product_ids' => $ids, 'options' => $options]
        );

        return redirect()
            ->route('products.index', ['tab' => 'all'])
            ->with('success', 'Shopify aktarımı kuyruğa alındı. Sağ alttan takip edebilirsiniz.')
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
                ['label' => 'Anasayfa', 'url' => route('dashboard')],
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
            'shopifyMetafields' => $this->formattedMetafields($product->shopifyProduct?->metafields ?? []),
            'shopifyConfigured' => $this->shopifyService->isConfigured(),
            'syncResults' => session('sync_results', []),
            'breadcrumbs' => [
                ['label' => 'Anasayfa', 'url' => route('dashboard')],
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
                ['label' => 'Anasayfa', 'url' => route('dashboard')],
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
            'variant_image_files' => ['nullable', 'array', 'max:50'],
            'variant_image_files.*' => ['nullable', 'image', 'max:5120'],
            'variant_image_urls' => ['nullable', 'array', 'max:50'],
            'variant_image_urls.*' => ['nullable', 'string', 'max:2000'],
            'variant_image_remove' => ['nullable', 'array', 'max:50'],
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

        $variantInfo = $product->variant_info;
        $variantImageChanged = false;
        if (is_array($variantInfo) && is_array($variantInfo['variants'] ?? null)) {
            $files = $request->file('variant_image_files', []);
            $urls = $request->input('variant_image_urls', []);
            $remove = $request->input('variant_image_remove', []);

            foreach ($variantInfo['variants'] as $index => $variant) {
                if (! is_array($variant)) {
                    continue;
                }

                $nextImage = trim((string) ($variant['image'] ?? ''));
                if (isset($remove[$index]) && (string) $remove[$index] === '1') {
                    $nextImage = '';
                    $variantImageChanged = true;
                }

                $url = trim((string) ($urls[$index] ?? ''));
                if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL)) {
                    $nextImage = $url;
                    $variantImageChanged = true;
                }

                $file = is_array($files) ? ($files[$index] ?? null) : null;
                if ($file) {
                    $path = $file->store('products/'.$product->id.'/variants', 'public');
                    $nextImage = url(Storage::disk('public')->url($path));
                    $variantImageChanged = true;
                    $hasNewImages = true;
                }

                $variantInfo['variants'][$index]['image'] = $nextImage !== '' ? $nextImage : null;
            }
        }

        $product->update([
            'title' => $validated['title'],
            'sku' => $validated['sku'] ?? null,
            'barcode' => $validated['barcode'] ?? null,
            'description' => $validated['description'] ?? null,
            'original_price' => $validated['original_price'],
            'stock' => $validated['stock'],
            'images' => $images,
            'variant_info' => $variantInfo,
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
                if (($hasNewImages || $variantImageChanged)
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
     * Pull Shopify images, metafields and collections into this product.
     */
    public function pullShopifyProduct(UyumSoftProduct $product): RedirectResponse
    {
        $redirect = redirect()->route('products.show', $product);
        $product->loadMissing('shopifyProduct');

        if (! $this->shopifyService->isConfigured()) {
            return $redirect->with('error', 'Shopify API ayarları eksik.');
        }

        if (blank($product->shopify_id) && blank($product->shopifyProduct?->shopify_product_id)) {
            return $redirect->with('error', 'Bu ürün henüz Shopify ile eşleşmemiş.');
        }

        $this->activityTracker->start(
            'shopify_pull',
            ($product->sku ?: $product->title).' Shopify’dan güncelle',
            1,
            ['product_id' => $product->id]
        );
        $this->activityTracker->markRunning('Shopify ürünü çekiliyor…');

        try {
            $result = $this->productSyncService->pullShopifyUpdates([$product->id]);
            $this->activityTracker->complete(
                $result['message'],
                (int) ($result['synced'] ?? 0),
                (int) ($result['errors'] ?? 0),
                ['skipped' => $result['skipped'] ?? 0]
            );

            if (($result['synced'] ?? 0) < 1) {
                return $redirect->with('warning', $result['message']);
            }

            return $redirect->with('success', $result['message']);
        } catch (ShopifyException $e) {
            $this->activityTracker->fail($e->getMessage(), $e);

            return $redirect->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            $this->activityTracker->fail($e->getMessage(), $e);
            report($e);

            return $redirect->with('error', 'Shopify’dan güncelleme başarısız: '.$e->getMessage());
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
            'images' => array_values(array_filter(array_map(
                static fn (array $image): string => trim((string) ($image['src'] ?? '')),
                $shopifyProduct->imageRows()
            ))),
            'shopifyMetafields' => $this->formattedMetafields($shopifyProduct->metafields ?? []),
            'adminUrl' => $this->shopifyService->adminProductUrl($shopifyProduct->shopify_product_id),
            'breadcrumbs' => [
                ['label' => 'Anasayfa', 'url' => route('dashboard')],
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

        if ($type === 'stock') {
            SyncStock::dispatch($activity->id);
        } else {
            SyncUyumSoftProducts::dispatch($limit, $offset, true, $type === 'reconcile', $activity->id);
        }

        return redirect()
            ->route('products.index')
            ->with('success', 'İşlem kuyruğa alındı. Sağ alttan ilerlemeyi takip edebilirsiniz.')
            ->with('sync_activity_uuid', $activity->uuid);
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

    /**
     * @param  array<int, int>  $productIds
     * @param  array<int, string>  $options
     * @param  array<string, mixed>  $meta
     */
    private function queueBulkProductAction(
        string $action,
        string $type,
        string $title,
        array $productIds,
        array $options = [],
        ?int $total = null,
        array $meta = []
    ): string {
        $activity = $this->activityTracker->start($type, $title, $total, $meta);
        ProcessBulkProductAction::dispatch(
            $action,
            $productIds,
            $options,
            $activity->id,
            Auth::id()
        );

        return $activity->uuid;
    }

    /**
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, array<string, mixed>>
     */
    private function formattedMetafields(array $fields): array
    {
        $ids = ShopifyMetafieldFormatter::productIdsFromFields($fields);
        $related = $ids === []
            ? collect()
            : ShopifyProduct::query()
                ->with('uyumSoftProduct')
                ->whereIn('shopify_product_id', $ids)
                ->get()
                ->keyBy('shopify_product_id');

        return ShopifyMetafieldFormatter::present($fields, $related);
    }
}
