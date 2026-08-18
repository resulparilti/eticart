<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ShopifyException;
use App\Exceptions\UyumSoftException;
use App\Models\AdminNotification;
use App\Models\Setting;
use App\Models\ShopifyProduct;
use App\Models\SyncJob;
use App\Models\SyncJobLog;
use App\Models\UyumSoftProduct;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProductSyncService
{
    public const OPTION_INFO = 'info';

    public const OPTION_IMAGES = 'images';

    public const OPTION_STOCK = 'stock';

    public const OPTION_PRICE = 'price';

    public const OPTION_ALL = 'all';

    public function __construct(
        private readonly UyumSoftService $uyumSoftService,
        private readonly ShopifyService $shopifyService,
        private readonly SyncActivityTracker $activityTracker,
        private readonly AdminNotificationService $notifications
    ) {
    }

    /**
     * Pull products from UyumSoft into local database.
     *
     * @return array{synced: int, errors: int, message: string}
     */
    public function syncFromUyumSoft(int $limit = 50, int $offset = 0): array
    {
        return $this->runTracked('product_sync', function () use ($limit, $offset) {
            $this->activityTracker->markRunning('UyumSoft ürünleri çekiliyor…');
            $this->activityTracker->log('info', "Sayfa çekiliyor (limit={$limit}, offset={$offset}).");

            $result = $this->uyumSoftService->getProducts($limit, $offset);
            $items = $result['items'] ?? [];
            $total = (int) ($result['total'] ?? count($items));
            $this->activityTracker->setTotal($total > 0 ? $total : count($items));

            $synced = 0;
            $errors = 0;

            foreach ($items as $item) {
                try {
                    $normalized = $this->uyumSoftService->normalizeProduct($item);
                    $this->upsertUyumSoftProduct($normalized);
                    $synced++;
                    $this->activityTracker->progress($synced, null, "{$synced} ürün işlendi…");
                } catch (Throwable $e) {
                    $errors++;
                    $this->activityTracker->log('error', 'Ürün kaydı başarısız: '.$e->getMessage());
                    Log::channel('stack')->error('UyumSoft product upsert failed', [
                        'message' => $e->getMessage(),
                        'item' => $item,
                    ]);
                }
            }

            $message = "{$synced} UyumSoft ürünü senkronize edildi".($errors ? ", {$errors} hata" : '').'.';
            $this->activityTracker->complete($message, $synced, $errors);

            return [
                'synced' => $synced,
                'errors' => $errors,
                'message' => $message,
            ];
        });
    }

    /**
     * Pull full UyumSoft catalog page-by-page, then optionally push diffs to Shopify.
     *
     * @param  array<int, string>  $shopifyOptions
     * @return array{synced: int, pushed: int, errors: int, pages: int, message: string}
     */
    public function syncAllFromUyumSoftAndReconcile(int $pageSize = 50, bool $pushToShopify = true, array $shopifyOptions = [self::OPTION_STOCK, self::OPTION_PRICE, self::OPTION_INFO]): array
    {
        return $this->runTracked('product_sync', function () use ($pageSize, $pushToShopify, $shopifyOptions) {
            $this->activityTracker->markRunning('UyumSoft kataloğu çekiliyor…');
            $this->activityTracker->log('info', 'Tam katalog senkronizasyonu başladı.');

            $synced = 0;
            $changed = 0;
            $unchanged = 0;
            $errors = 0;
            $pages = 0;
            $offset = 0;
            $maxPages = 100;
            $changedIds = [];
            $totalKnown = null;

            do {
                $result = $this->uyumSoftService->getProducts($pageSize, $offset);
                $pages++;
                $items = $result['items'] ?? [];
                $total = (int) ($result['total'] ?? count($items));
                if ($totalKnown === null && $total > 0) {
                    $totalKnown = $total;
                    $this->activityTracker->setTotal($totalKnown);
                }

                $this->activityTracker->log('info', "Sayfa {$pages} alındı (".count($items).' kayıt).');

                foreach ($items as $item) {
                    try {
                        $normalized = $this->uyumSoftService->normalizeProduct($item);
                        $upsert = $this->upsertUyumSoftProduct($normalized);
                        $local = $upsert['product'];
                        if ($upsert['changed']) {
                            $changedIds[] = $local->id;
                            $changed++;
                        } else {
                            $unchanged++;
                        }
                        $synced++;
                        $this->activityTracker->progress(
                            $synced,
                            $totalKnown,
                            "UyumSoft: {$synced}".($totalKnown ? "/{$totalKnown}" : '').' ürün'
                        );
                    } catch (Throwable $e) {
                        $errors++;
                        $this->activityTracker->log('error', 'Ürün senkron hatası: '.$e->getMessage());
                        Log::channel('stack')->error('UyumSoft full sync item failed', [
                            'message' => $e->getMessage(),
                        ]);
                    }
                }

                $offset += $pageSize;
                $hasMore = $items !== [] && $offset < max($total, $offset);
            } while ($hasMore && $pages < $maxPages);

            $pushed = 0;
            if ($pushToShopify && $changedIds !== [] && $this->shopifyService->isConfigured()) {
                $this->activityTracker->log('info', 'Değişen ürünler Shopify ile eşitleniyor…');
                $this->activityTracker->progress($synced, $totalKnown, 'Shopify ile eşitleniyor…');

                $pushIds = UyumSoftProduct::query()
                    ->whereIn('id', $changedIds)
                    ->where('is_active', true)
                    ->where(function ($q) {
                        $q->where('synced_to_shopify', true)->orWhereNotNull('shopify_id');
                    })
                    ->pluck('id')
                    ->all();

                if ($pushIds !== []) {
                    $pushResult = $this->syncToShopify($pushIds, $shopifyOptions);
                    $pushed = (int) ($pushResult['synced'] ?? 0);
                    $errors += (int) ($pushResult['errors'] ?? 0);
                    $this->activityTracker->log(
                        ($pushResult['errors'] ?? 0) > 0 ? 'warning' : 'success',
                        $pushResult['message'] ?? "{$pushed} ürün Shopify’a eşitlendi."
                    );
                } else {
                    $this->activityTracker->log('info', 'Shopify’a eşitlenecek aktif ürün bulunamadı.');
                }
            }

            $message = "{$synced} ürün tarandı"
                .($changed > 0 ? ", {$changed} değişti" : ', değişiklik yok')
                .($unchanged > 0 ? ", {$unchanged} aynı bırakıldı" : '')
                .($pushed > 0 ? ", {$pushed} Shopify'a eşitlendi" : '')
                .($errors > 0 ? ", {$errors} hata" : '')
                .'.';

            $this->activityTracker->complete($message, $synced, $errors, [
                'pushed' => $pushed,
                'changed' => $changed,
                'unchanged' => $unchanged,
                'pages' => $pages,
            ]);

            return [
                'synced' => $synced,
                'pushed' => $pushed,
                'errors' => $errors,
                'pages' => $pages,
                'message' => $message,
            ];
        });
    }

    /**
     * Push selected local UyumSoft products to Shopify with options.
     *
     * @param  array<int, int>  $uyumSoftProductIds
     * @param  array<int, string>  $options
     * @return array{synced: int, errors: int, skipped: int, message: string, results: array<int, array<string, mixed>>}
     */
    public function syncToShopify(array $uyumSoftProductIds, array $options = [self::OPTION_ALL]): array
    {
        if (! $this->shopifyService->isConfigured()) {
            throw new ShopifyException('Shopify API bilgileri yapılandırılmamış.');
        }

        $options = $this->normalizeOptions($options);

        $this->linkUnmappedUyumSoftProducts();

        $products = UyumSoftProduct::query()
            ->whereIn('id', $uyumSoftProductIds)
            ->with('shopifyProduct')
            ->get();

        $synced = 0;
        $errors = 0;
        $skipped = 0;
        $results = [];
        $total = $products->count();
        $done = 0;

        if ($this->activityTracker->current()) {
            $this->activityTracker->log('info', "Shopify aktarımı: {$total} ürün.");
        }

        foreach ($products as $product) {
            $done++;
            if (! $product->is_active) {
                $skipped++;
                $results[] = [
                    'id' => $product->id,
                    'title' => $product->title,
                    'status' => 'skipped',
                    'message' => 'Pasif ürün — Shopify aktarımı atlandı.',
                ];
                continue;
            }

            try {
                $shopifyProduct = $this->pushProductToShopify($product, $options);
                $synced++;
                $results[] = [
                    'id' => $product->id,
                    'title' => $product->title,
                    'status' => 'success',
                    'shopify_product_id' => $shopifyProduct->shopify_product_id,
                    'options' => $options,
                ];
            } catch (Throwable $e) {
                $errors++;
                $results[] = [
                    'id' => $product->id,
                    'title' => $product->title,
                    'status' => 'error',
                    'message' => $e->getMessage(),
                ];
                if ($this->activityTracker->current()) {
                    $this->activityTracker->log('error', $product->title.': '.$e->getMessage());
                }
                Log::channel('stack')->error('Shopify product push failed', [
                    'uyumsoft_product_id' => $product->id,
                    'message' => $e->getMessage(),
                ]);
            }

            if ($this->activityTracker->current()) {
                $this->activityTracker->progress(
                    $done,
                    $total,
                    "Shopify: {$done}/{$total}"
                );
            }
        }

        return [
            'synced' => $synced,
            'errors' => $errors,
            'skipped' => $skipped,
            'message' => "{$synced} ürün aktarıldı"
                .($skipped ? ", {$skipped} pasif atlandı" : '')
                .($errors ? ", {$errors} hata" : '').'.',
            'results' => $results,
        ];
    }

    /**
     * Push all active, already-synced products (periodic equality).
     *
     * @param  array<int, string>  $options
     * @return array{synced: int, errors: int, skipped: int, message: string, results: array<int, array<string, mixed>>}
     */
    public function syncActiveToShopify(array $options = [self::OPTION_STOCK, self::OPTION_PRICE]): array
    {
        $ids = UyumSoftProduct::query()
            ->where('is_active', true)
            ->where('synced_to_shopify', true)
            ->whereNotNull('shopify_id')
            ->pluck('id')
            ->all();

        if ($ids === []) {
            return [
                'synced' => 0,
                'errors' => 0,
                'skipped' => 0,
                'message' => 'Eşitlenecek aktif Shopify ürünü yok.',
                'results' => [],
            ];
        }

        return $this->syncToShopify($ids, $options);
    }

    /**
     * Sync stock from UyumSoft to local + Shopify.
     *
     * @return array{synced: int, errors: int, message: string}
     */
    public function syncStock(): array
    {
        return $this->runTracked('stock_sync', function () {
            $this->activityTracker->markRunning('Stoklar yenileniyor…');
            $stocks = $this->uyumSoftService->getStocks();
            $synced = 0;
            $changed = 0;
            $errors = 0;

            if ($stocks === []) {
                $result = $this->uyumSoftService->getProducts(100, 0);
                $stocks = $result['items'];
            }

            $this->activityTracker->setTotal(count($stocks));
            $this->activityTracker->log('info', count($stocks).' stok kaydı kontrol edilecek.');

            foreach ($stocks as $stockItem) {
                try {
                    $normalized = $this->uyumSoftService->normalizeProduct($stockItem);
                    $upsert = $this->upsertUyumSoftProduct($normalized);
                    $local = $upsert['product'];

                    if ($upsert['changed']) {
                        $changed++;
                        if ($local->is_active && $local->synced_to_shopify) {
                            try {
                                $this->pushProductToShopify($local, [self::OPTION_STOCK]);
                                $this->activityTracker->log('success', ($local->sku ?: $local->title).' stok Shopify’a yazıldı.');
                            } catch (Throwable $e) {
                                Log::channel('stack')->warning('Shopify stock push skipped', [
                                    'product_id' => $local->id,
                                    'message' => $e->getMessage(),
                                ]);
                            }
                        }
                    }

                    $synced++;
                    $this->activityTracker->progress($synced, count($stocks), "Stok: {$synced}/".count($stocks));
                } catch (Throwable $e) {
                    $errors++;
                    $this->activityTracker->log('error', 'Stok güncelleme hatası: '.$e->getMessage());
                    Log::channel('stack')->error('Stock sync item failed', [
                        'message' => $e->getMessage(),
                        'item' => $stockItem,
                    ]);
                }
            }

            $message = "{$synced} stok kaydı kontrol edildi"
                .($changed > 0 ? ", {$changed} değişti" : ', değişiklik yok')
                .($errors ? ", {$errors} hata" : '')
                .'.';
            $this->activityTracker->complete($message, $synced, $errors, ['changed' => $changed]);

            return [
                'synced' => $synced,
                'errors' => $errors,
                'message' => $message,
            ];
        });
    }

    /**
     * Upsert local UyumSoft product (does not overwrite is_active or local images if empty remote).
     *
     * @param  array<string, mixed>  $data
     * @return array{product: UyumSoftProduct, created: bool, changed: bool}
     */
    public function upsertUyumSoftProduct(array $data): array
    {
        $existing = UyumSoftProduct::query()
            ->where('uyumsoft_id', (string) $data['uyumsoft_id'])
            ->first();

        $payload = [
            'sku' => $data['sku'] ?? null,
            'barcode' => ($data['barcode'] ?? '') !== '' ? $data['barcode'] : ($existing?->barcode),
            'title' => $data['title'],
            'description' => ($data['description'] ?? '') !== '' ? $data['description'] : ($existing?->description),
            'variant_info' => $data['variant_info'] ?? null,
            'original_price' => $data['original_price'] ?? 0,
            'stock' => $data['stock'] ?? 0,
            'last_sync' => now(),
        ];

        $remoteImages = $data['images'] ?? [];
        if (is_array($remoteImages) && $remoteImages !== []) {
            $payload['images'] = $remoteImages;
        } elseif ($existing) {
            $payload['images'] = $existing->images;
        }

        $hash = $this->sourceHash($payload);
        $payload['source_hash'] = $hash;
        $businessChanged = $existing === null || $this->hasBusinessChange($existing, $payload);

        if ($existing && $existing->source_hash === $hash) {
            $existing->forceFill(['last_sync' => now()])->saveQuietly();

            return [
                'product' => $existing->fresh() ?? $existing,
                'created' => false,
                'changed' => false,
            ];
        }

        $product = UyumSoftProduct::query()->updateOrCreate(
            ['uyumsoft_id' => (string) $data['uyumsoft_id']],
            $payload
        );

        $result = [
            'product' => $product,
            'created' => $existing === null,
            'changed' => $businessChanged,
        ];
        $this->notifyProductChange($result);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function hasBusinessChange(UyumSoftProduct $existing, array $payload): bool
    {
        return (string) $existing->sku !== (string) ($payload['sku'] ?? '')
            || (string) $existing->barcode !== (string) ($payload['barcode'] ?? '')
            || (string) $existing->title !== (string) ($payload['title'] ?? '')
            || (string) ($existing->description ?? '') !== (string) ($payload['description'] ?? '')
            || (float) $existing->original_price !== (float) ($payload['original_price'] ?? 0)
            || (int) $existing->stock !== (int) ($payload['stock'] ?? 0)
            || json_encode($existing->variant_info) !== json_encode($payload['variant_info'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function sourceHash(array $payload): string
    {
        $canonical = [
            'sku' => (string) ($payload['sku'] ?? ''),
            'barcode' => (string) ($payload['barcode'] ?? ''),
            'title' => (string) ($payload['title'] ?? ''),
            'description' => (string) ($payload['description'] ?? ''),
            'variant_info' => $payload['variant_info'] ?? null,
            'original_price' => (string) ($payload['original_price'] ?? '0'),
            'stock' => (int) ($payload['stock'] ?? 0),
            'images' => $payload['images'] ?? null,
        ];

        return hash('sha256', (string) json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param  array{product: UyumSoftProduct, created: bool, changed: bool}  $result
     */
    private function notifyProductChange(array $result): void
    {
        $product = $result['product'];

        if ($result['created']) {
            $this->notifications->notify(
                AdminNotification::TYPE_PRODUCT_CREATED,
                'Yeni ürün: '.$product->title,
                trim(($product->sku ?: '').' · stok '.$product->stock),
                route('products.show', $product),
                $product
            );

            return;
        }

        if ($result['changed']) {
            $this->notifications->notify(
                AdminNotification::TYPE_PRODUCT_UPDATED,
                'Ürün güncellendi: '.$product->title,
                'Stok, fiyat veya varyant bilgisi değişti.',
                route('products.show', $product),
                $product
            );
        }
    }

    /**
     * Create or update Shopify product with selective options.
     *
     * @param  array<int, string>  $options
     */
    public function pushProductToShopify(UyumSoftProduct $product, array $options = [self::OPTION_ALL]): ShopifyProduct
    {
        if (! $product->is_active) {
            throw new ShopifyException('Pasif ürünler Shopify\'a aktarılamaz. Önce aktifleştirin veya durum senkronu kullanın.');
        }

        $options = $this->normalizeOptions($options);

        return DB::transaction(function () use ($product, $options) {
            $product->loadMissing('shopifyProduct');
            $shopifyId = $product->shopify_id ?: $this->resolveExistingShopifyProductId($product);
            $remote = null;
            $isCreate = blank($shopifyId);

            if ($isCreate || in_array(self::OPTION_INFO, $options, true) || in_array(self::OPTION_IMAGES, $options, true)) {
                $payload = [];

                if ($isCreate || in_array(self::OPTION_INFO, $options, true)) {
                    $payload['title'] = $product->title;
                    $payload['body_html'] = $product->description ?: '';
                    $payload['status'] = 'active';
                    $payload = array_merge($payload, $this->buildShopifyVariantPayload($product, $isCreate));
                }

                if ($isCreate || in_array(self::OPTION_IMAGES, $options, true)) {
                    $images = $product->imageUrls();
                    if ($images !== []) {
                        $payload['images'] = array_map(static fn (string $src) => ['src' => $src], $images);
                    }
                }

                if ($isCreate) {
                    $remote = $this->shopifyService->createProduct($payload);
                    $shopifyId = (string) ($remote['id'] ?? '');
                } else {
                    $updatePayload = array_filter([
                        'id' => $shopifyId,
                        'title' => $payload['title'] ?? null,
                        'body_html' => array_key_exists('body_html', $payload) ? $payload['body_html'] : null,
                        'status' => $payload['status'] ?? null,
                        'images' => $payload['images'] ?? null,
                    ], static fn ($v) => $v !== null);
                    $remote = $this->shopifyService->updateProduct($shopifyId, $updatePayload);
                }
            }

            if ($shopifyId && (in_array(self::OPTION_PRICE, $options, true) || in_array(self::OPTION_STOCK, $options, true))) {
                $details = $remote ?: $this->shopifyService->getProductDetails($shopifyId);
                $remoteVariants = is_array($details['variants'] ?? null) ? $details['variants'] : [];
                $this->syncShopifyVariantPriceAndStock($product, $remoteVariants, $options);
                $remote = $details;
            }

            if (! $remote && $shopifyId) {
                $remote = $this->shopifyService->getProductDetails($shopifyId);
            }

            $variant = $remote['variants'][0] ?? [];
            $remoteVariants = is_array($remote['variants'] ?? null) ? $remote['variants'] : [];

            $shopifyProduct = ShopifyProduct::query()->updateOrCreate(
                [
                    'shopify_product_id' => (string) ($remote['id'] ?? $shopifyId),
                ],
                [
                    'shopify_variant_id' => isset($variant['id'])
                        ? (string) $variant['id']
                        : $product->shopifyProduct?->shopify_variant_id,
                    'inventory_item_id' => isset($variant['inventory_item_id'])
                        ? (string) $variant['inventory_item_id']
                        : $product->shopifyProduct?->inventory_item_id,
                    'title' => (string) ($remote['title'] ?? $product->title),
                    'description' => (string) ($remote['body_html'] ?? $product->description ?? ''),
                    'sku' => $variant['sku'] ?? $product->sku,
                    'price' => (float) ($variant['price'] ?? $product->original_price),
                    'stock' => (int) $product->stock,
                    'variant_count' => count($remoteVariants) > 0 ? count($remoteVariants) : max(1, count($product->variantRows())),
                    'variants' => $remoteVariants,
                    'images' => $remote['images'] ?? $product->images,
                    'status' => (string) ($remote['status'] ?? 'active'),
                    'handle' => (string) ($remote['handle'] ?? ''),
                    'uyumsoft_product_id' => $product->id,
                    'last_sync' => now(),
                ]
            );

            $product->update([
                'synced_to_shopify' => true,
                'shopify_id' => (string) ($remote['id'] ?? $shopifyId),
                'shopify_synced_at' => now(),
                'last_sync' => now(),
            ]);

            return $shopifyProduct;
        });
    }

    /**
     * Lokal aktif/pasif durumunu Shopify product status (active/draft) olarak yazar.
     *
     * @return array{success: bool, skipped: bool, message: string, status?: string}
     */
    public function pushShopifyActiveStatus(UyumSoftProduct $product): array
    {
        $product->refresh();

        if (blank($product->shopify_id) && ! $product->synced_to_shopify) {
            return [
                'success' => true,
                'skipped' => true,
                'message' => $product->sku.' Shopify’da yok; durum push atlandı.',
            ];
        }

        if (! $this->shopifyService->isConfigured()) {
            return [
                'success' => false,
                'skipped' => true,
                'message' => 'Shopify yapılandırılmamış.',
            ];
        }

        $shopifyId = (string) ($product->shopify_id ?: $product->shopifyProduct?->shopify_product_id);
        if ($shopifyId === '') {
            return [
                'success' => true,
                'skipped' => true,
                'message' => $product->sku.' Shopify ID yok.',
            ];
        }

        $status = $product->is_active ? 'active' : 'draft';
        $this->shopifyService->updateProduct($shopifyId, [
            'id' => $shopifyId,
            'status' => $status,
        ]);

        ShopifyProduct::query()
            ->where('shopify_product_id', $shopifyId)
            ->update(['status' => $status, 'last_sync' => now()]);

        $label = $product->is_active ? 'aktif' : 'pasif (draft)';
        $message = ($product->sku ?: $product->title)." Shopify’da {$label} yapıldı.";
        $this->activityTracker->log('success', $message);

        return [
            'success' => true,
            'skipped' => false,
            'message' => $message,
            'status' => $status,
        ];
    }

    /**
     * @param  array<int, int>  $productIds
     * @return array{synced: int, skipped: int, errors: int, message: string}
     */
    public function syncActiveStatusToShopify(array $productIds): array
    {
        $synced = 0;
        $skipped = 0;
        $errors = 0;

        $products = UyumSoftProduct::query()->whereIn('id', $productIds)->get();
        foreach ($products as $product) {
            try {
                $result = $this->pushShopifyActiveStatus($product);
                if (! empty($result['skipped'])) {
                    $skipped++;
                } elseif (! empty($result['success'])) {
                    $synced++;
                } else {
                    $errors++;
                }
            } catch (Throwable $e) {
                $errors++;
                $this->activityTracker->log('error', ($product->sku ?: '#'.$product->id).' durum push hatası: '.$e->getMessage());
            }
        }

        return [
            'synced' => $synced,
            'skipped' => $skipped,
            'errors' => $errors,
            'message' => "{$synced} ürün Shopify durumu güncellendi"
                .($skipped ? ", {$skipped} atlandı" : '')
                .($errors ? ", {$errors} hata" : '')
                .'.',
        ];
    }

    /**
     * Build Shopify options + variants from local UyumSoft variant_info.
     *
     * @return array<string, mixed>
     */
    private function buildShopifyVariantPayload(UyumSoftProduct $product, bool $includeInventoryQty): array
    {
        $rows = $product->variantRows();
        $groups = $product->attributeGroups();

        if ($rows === []) {
            $variant = [
                'price' => (string) $product->original_price,
                'sku' => $product->sku ?: $product->uyumsoft_id,
                'inventory_management' => 'shopify',
            ];
            if ($product->barcode) {
                $variant['barcode'] = $product->barcode;
            }
            if ($includeInventoryQty) {
                $variant['inventory_quantity'] = (int) $product->stock;
            }

            return ['variants' => [$variant]];
        }

        $payload = [];
        if ($groups !== []) {
            $payload['options'] = array_map(static fn (array $g): array => [
                'name' => $g['name'],
                'values' => $g['values'],
            ], array_slice($groups, 0, 3));
        }

        $variants = [];
        foreach ($rows as $row) {
            $variant = [
                'price' => (string) ($row['price'] ?? $product->original_price),
                'sku' => (string) (($row['sku'] ?? null) ?: ($product->sku ?: $product->uyumsoft_id)),
                'inventory_management' => 'shopify',
            ];
            if (! empty($row['barcode'])) {
                $variant['barcode'] = (string) $row['barcode'];
            }
            if (! empty($row['attribute_1'])) {
                $variant['option1'] = (string) $row['attribute_1'];
            }
            if (! empty($row['attribute_2'])) {
                $variant['option2'] = (string) $row['attribute_2'];
            }
            if (! empty($row['attribute_3'])) {
                $variant['option3'] = (string) $row['attribute_3'];
            }
            if ($includeInventoryQty) {
                $variant['inventory_quantity'] = (int) ($row['stock'] ?? 0);
            }
            $variants[] = $variant;
        }

        $payload['variants'] = $variants;

        return $payload;
    }

    /**
     * Match Shopify variants by barcode/sku and update price/stock.
     *
     * @param  array<int, array<string, mixed>>  $remoteVariants
     * @param  array<int, string>  $options
     */
    private function syncShopifyVariantPriceAndStock(UyumSoftProduct $product, array $remoteVariants, array $options): void
    {
        $localRows = $product->variantRows();
        if ($localRows === [] || $remoteVariants === []) {
            $variantId = $product->shopifyProduct?->shopify_variant_id;
            $inventoryItemId = $product->shopifyProduct?->inventory_item_id;
            $first = $remoteVariants[0] ?? [];
            $variantId = $variantId ?: (isset($first['id']) ? (string) $first['id'] : null);
            $inventoryItemId = $inventoryItemId ?: (isset($first['inventory_item_id']) ? (string) $first['inventory_item_id'] : null);

            if ($variantId && in_array(self::OPTION_PRICE, $options, true)) {
                $this->shopifyService->updateProductVariant($variantId, [
                    'id' => $variantId,
                    'price' => (string) $product->original_price,
                    'sku' => $product->sku ?: $product->uyumsoft_id,
                ]);
            }
            if ($inventoryItemId && in_array(self::OPTION_STOCK, $options, true)) {
                try {
                    $this->shopifyService->updateInventory($inventoryItemId, (int) $product->stock);
                } catch (ShopifyException $e) {
                    Log::channel('stack')->warning('Inventory update failed', [
                        'product_id' => $product->id,
                        'message' => $e->getMessage(),
                    ]);
                }
            }

            return;
        }

        foreach ($localRows as $local) {
            $match = $this->matchRemoteVariant($remoteVariants, $local);
            if ($match === null) {
                continue;
            }

            $variantId = isset($match['id']) ? (string) $match['id'] : null;
            $inventoryItemId = isset($match['inventory_item_id']) ? (string) $match['inventory_item_id'] : null;

            if ($variantId && in_array(self::OPTION_PRICE, $options, true)) {
                $payload = [
                    'id' => $variantId,
                    'price' => (string) ($local['price'] ?? $product->original_price),
                ];
                if (! empty($local['sku'])) {
                    $payload['sku'] = (string) $local['sku'];
                }
                if (! empty($local['barcode'])) {
                    $payload['barcode'] = (string) $local['barcode'];
                }
                $this->shopifyService->updateProductVariant($variantId, $payload);
            }

            if ($inventoryItemId && in_array(self::OPTION_STOCK, $options, true)) {
                try {
                    $this->shopifyService->updateInventory($inventoryItemId, (int) ($local['stock'] ?? 0));
                } catch (ShopifyException $e) {
                    Log::channel('stack')->warning('Variant inventory update failed', [
                        'product_id' => $product->id,
                        'sku' => $local['sku'] ?? null,
                        'message' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $remoteVariants
     * @param  array<string, mixed>  $local
     * @return array<string, mixed>|null
     */
    private function matchRemoteVariant(array $remoteVariants, array $local): ?array
    {
        $barcode = (string) ($local['barcode'] ?? '');
        $sku = (string) ($local['sku'] ?? '');

        foreach ($remoteVariants as $remote) {
            if (! is_array($remote)) {
                continue;
            }
            if ($barcode !== '' && (string) ($remote['barcode'] ?? '') === $barcode) {
                return $remote;
            }
        }

        foreach ($remoteVariants as $remote) {
            if (! is_array($remote)) {
                continue;
            }
            if ($sku !== '' && (string) ($remote['sku'] ?? '') === $sku) {
                return $remote;
            }
        }

        return null;
    }

    /**
     * Pull products from Shopify into local mirror table.
     *
     * @return array{synced: int, linked: int, errors: int, pages: int, message: string}
     */
    public function pullFromShopify(int $limitPerPage = 250): array
    {
        if (! $this->shopifyService->isConfigured()) {
            throw new ShopifyException('Shopify API ayarları eksik.');
        }

        return $this->runTracked('shopify_product_pull', function () use ($limitPerPage) {
            $synced = 0;
            $linked = 0;
            $errors = 0;
            $pageInfo = null;
            $pages = 0;
            $maxPages = 40;

            do {
                $result = $this->shopifyService->getProducts($limitPerPage, $pageInfo);
                $pages++;

                foreach ($result['products'] as $remoteProduct) {
                    try {
                        $upsert = $this->upsertShopifyRemoteProduct($remoteProduct);
                        $synced += $upsert['rows'];
                        $linked += $upsert['linked'];
                    } catch (Throwable $e) {
                        $errors++;
                        Log::channel('stack')->error('Shopify product pull failed', [
                            'shopify_product_id' => $remoteProduct['id'] ?? null,
                            'message' => $e->getMessage(),
                        ]);
                    }
                }

                $pageInfo = $result['next_page_info'] ?? null;
            } while ($pageInfo && $pages < $maxPages);

            $linkResult = $this->linkUnmappedUyumSoftProducts();
            $linked += $linkResult['linked'];

            return [
                'synced' => $synced,
                'linked' => $linked,
                'errors' => $errors,
                'pages' => $pages,
                'message' => "{$synced} Shopify ana ürün kaydı güncellendi"
                    .($linked > 0 ? ", {$linked} UyumSoft eşleşmesi" : '')
                    .($errors > 0 ? ", {$errors} hata" : '')
                    .'.',
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $remote
     * @return array{rows: int, linked: int}
     */
    private function upsertShopifyRemoteProduct(array $remote): array
    {
        $productId = (string) ($remote['id'] ?? '');
        if ($productId === '') {
            return ['rows' => 0, 'linked' => 0];
        }

        $variants = $remote['variants'] ?? [];
        if (! is_array($variants) || $variants === []) {
            $variants = [[]];
        }

        $normalizedVariants = [];
        foreach ($variants as $variant) {
            if (! is_array($variant)) {
                continue;
            }

            $normalizedVariants[] = [
                'id' => isset($variant['id']) ? (string) $variant['id'] : '',
                'title' => (string) ($variant['title'] ?? 'Varsayılan'),
                'sku' => filled($variant['sku'] ?? null) ? (string) $variant['sku'] : null,
                'price' => (float) ($variant['price'] ?? 0),
                'compare_at_price' => isset($variant['compare_at_price']) ? (float) $variant['compare_at_price'] : null,
                'stock' => (int) ($variant['inventory_quantity'] ?? 0),
                'barcode' => filled($variant['barcode'] ?? null) ? (string) $variant['barcode'] : null,
                'inventory_item_id' => isset($variant['inventory_item_id'])
                    ? (string) $variant['inventory_item_id']
                    : null,
            ];
        }

        if ($normalizedVariants === []) {
            $normalizedVariants[] = [
                'id' => '',
                'title' => 'Varsayılan',
                'sku' => null,
                'price' => 0.0,
                'compare_at_price' => null,
                'stock' => 0,
                'barcode' => null,
                'inventory_item_id' => null,
            ];
        }

        $prices = array_map(static fn (array $v): float => (float) ($v['price'] ?? 0), $normalizedVariants);
        $totalStock = array_sum(array_column($normalizedVariants, 'stock'));
        $primaryVariant = $normalizedVariants[0];
        $primarySku = collect($normalizedVariants)->pluck('sku')->filter()->first();

        $uyumsoft = $this->resolveUyumSoftForShopifyProduct(
            $productId,
            is_string($primarySku) ? $primarySku : null,
            (string) ($remote['title'] ?? ''),
            $this->extractBarcodesFromShopifyVariants($normalizedVariants)
        );
        if (! $uyumsoft && $primarySku) {
            foreach ($normalizedVariants as $variant) {
                $candidate = $this->resolveUyumSoftForShopifyProduct(
                    $productId,
                    $variant['sku'] ?? null,
                    (string) ($remote['title'] ?? ''),
                    $this->extractBarcodesFromShopifyVariants([$variant])
                );
                if ($candidate) {
                    $uyumsoft = $candidate;
                    break;
                }
            }
        }

        $images = [];
        foreach ($remote['images'] ?? [] as $image) {
            if (! is_array($image)) {
                continue;
            }
            $src = (string) ($image['src'] ?? '');
            if ($src === '') {
                continue;
            }
            $images[] = [
                'src' => $src,
                'alt' => $image['alt'] ?? null,
                'position' => (int) ($image['position'] ?? 0),
            ];
        }

        usort($images, static fn (array $a, array $b): int => ($a['position'] ?? 0) <=> ($b['position'] ?? 0));

        $shopifyProduct = ShopifyProduct::query()->updateOrCreate(
            ['shopify_product_id' => $productId],
            [
                'shopify_variant_id' => $primaryVariant['id'] !== '' ? $primaryVariant['id'] : null,
                'inventory_item_id' => $primaryVariant['inventory_item_id'],
                'title' => (string) ($remote['title'] ?? 'Shopify Ürün'),
                'description' => (string) ($remote['body_html'] ?? ''),
                'images' => $images,
                'variants' => $normalizedVariants,
                'status' => (string) ($remote['status'] ?? 'active'),
                'handle' => filled($remote['handle'] ?? null) ? (string) $remote['handle'] : null,
                'sku' => is_string($primarySku) ? $primarySku : null,
                'price' => min($prices),
                'price_max' => max($prices),
                'stock' => $totalStock,
                'variant_count' => count($normalizedVariants),
                'uyumsoft_product_id' => $uyumsoft?->id,
                'last_sync' => now(),
            ]
        );

        ShopifyProduct::query()
            ->where('shopify_product_id', $productId)
            ->where('id', '!=', $shopifyProduct->id)
            ->forceDelete();

        $linked = 0;
        if ($uyumsoft) {
            $uyumsoft->update([
                'synced_to_shopify' => true,
                'shopify_id' => $productId,
                'shopify_synced_at' => now(),
            ]);
            $linked = 1;
        }

        return ['rows' => 1, 'linked' => $linked];
    }

    private function resolveUyumSoftForShopifyProduct(
        string $shopifyProductId,
        ?string $sku,
        ?string $title = null,
        array $barcodes = []
    ): ?UyumSoftProduct {
        $byShopifyId = UyumSoftProduct::query()->where('shopify_id', $shopifyProductId)->first();
        if ($byShopifyId) {
            return $byShopifyId;
        }

        if ($sku) {
            $bySku = UyumSoftProduct::query()->where('sku', $sku)->first();
            if ($bySku) {
                return $bySku;
            }

            $byUyumId = UyumSoftProduct::query()->where('uyumsoft_id', $sku)->first();
            if ($byUyumId) {
                return $byUyumId;
            }
        }

        foreach (array_filter($barcodes) as $barcode) {
            $byBarcode = UyumSoftProduct::query()->where('barcode', $barcode)->first();
            if ($byBarcode) {
                return $byBarcode;
            }

            $byVariantBarcode = UyumSoftProduct::query()
                ->where('variant_info', 'like', '%'.$barcode.'%')
                ->first();
            if ($byVariantBarcode) {
                return $byVariantBarcode;
            }
        }

        if (filled($title)) {
            $normalizedTitle = $this->normalizeProductMatchKey($title);
            if ($normalizedTitle !== '') {
                $candidates = UyumSoftProduct::query()
                    ->whereNull('shopify_id')
                    ->get();

                foreach ($candidates as $candidate) {
                    if ($this->normalizeProductMatchKey((string) $candidate->title) === $normalizedTitle) {
                        return $candidate;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Link UyumSoft products to existing Shopify mirror rows by title + barcode.
     *
     * @return array{linked: int, scanned: int}
     */
    public function linkUnmappedUyumSoftProducts(): array
    {
        $linked = 0;
        $scanned = 0;

        $products = UyumSoftProduct::query()
            ->whereNull('shopify_id')
            ->where('is_active', true)
            ->get();

        foreach ($products as $product) {
            $scanned++;
            $shopifyId = $this->resolveExistingShopifyProductId($product);
            if ($shopifyId === null) {
                continue;
            }

            $product->update([
                'shopify_id' => $shopifyId,
                'synced_to_shopify' => true,
                'shopify_synced_at' => now(),
            ]);

            ShopifyProduct::query()
                ->where('shopify_product_id', $shopifyId)
                ->update(['uyumsoft_product_id' => $product->id]);

            $linked++;
        }

        return ['linked' => $linked, 'scanned' => $scanned];
    }

    private function resolveExistingShopifyProductId(UyumSoftProduct $product): ?string
    {
        if (filled($product->shopify_id)) {
            return (string) $product->shopify_id;
        }

        $product->loadMissing('shopifyProduct');
        if ($product->shopifyProduct?->shopify_product_id) {
            return (string) $product->shopifyProduct->shopify_product_id;
        }

        $barcodes = $this->collectUyumSoftBarcodes($product);
        foreach ($barcodes as $barcode) {
            $match = ShopifyProduct::query()
                ->where('variants', 'like', '%'.$barcode.'%')
                ->first();
            if ($match) {
                return (string) $match->shopify_product_id;
            }
        }

        $normalizedTitle = $this->normalizeProductMatchKey((string) $product->title);
        if ($normalizedTitle !== '') {
            $candidates = ShopifyProduct::query()->get();
            foreach ($candidates as $candidate) {
                if ($this->normalizeProductMatchKey((string) $candidate->title) === $normalizedTitle) {
                    return (string) $candidate->shopify_product_id;
                }
            }
        }

        if (filled($product->sku)) {
            $bySku = ShopifyProduct::query()->where('sku', $product->sku)->first();
            if ($bySku) {
                return (string) $bySku->shopify_product_id;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $variants
     * @return array<int, string>
     */
    private function extractBarcodesFromShopifyVariants(array $variants): array
    {
        $barcodes = [];
        foreach ($variants as $variant) {
            $barcode = trim((string) ($variant['barcode'] ?? ''));
            if ($barcode !== '') {
                $barcodes[] = $barcode;
            }
        }

        return array_values(array_unique($barcodes));
    }

    /**
     * @return array<int, string>
     */
    private function collectUyumSoftBarcodes(UyumSoftProduct $product): array
    {
        $barcodes = [];
        if (filled($product->barcode)) {
            $barcodes[] = (string) $product->barcode;
        }

        foreach ($product->variantRows() as $variant) {
            $barcode = trim((string) ($variant['barcode'] ?? ''));
            if ($barcode !== '') {
                $barcodes[] = $barcode;
            }
        }

        return array_values(array_unique($barcodes));
    }

    private function normalizeProductMatchKey(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    /**
     * @param  array<int, int>  $ids
     * @return Collection<int, array<string, mixed>>
     */
    public function previewForShopify(array $ids): Collection
    {
        return UyumSoftProduct::query()
            ->whereIn('id', $ids)
            ->get()
            ->map(fn (UyumSoftProduct $product) => [
                'id' => $product->id,
                'title' => $product->title,
                'sku' => $product->sku,
                'price' => $product->original_price,
                'stock' => $product->stock,
                'is_active' => $product->is_active,
                'already_synced' => (bool) $product->synced_to_shopify,
            ]);
    }

    /**
     * @param  array<int, string>  $options
     * @return array<int, string>
     */
    public function normalizeOptions(array $options): array
    {
        $allowed = [
            self::OPTION_INFO,
            self::OPTION_IMAGES,
            self::OPTION_STOCK,
            self::OPTION_PRICE,
            self::OPTION_ALL,
        ];

        $options = array_values(array_unique(array_filter($options, static fn ($o) => in_array($o, $allowed, true))));

        if ($options === [] || in_array(self::OPTION_ALL, $options, true)) {
            return [
                self::OPTION_INFO,
                self::OPTION_IMAGES,
                self::OPTION_STOCK,
                self::OPTION_PRICE,
            ];
        }

        return $options;
    }

    /**
     * @param  callable(): array{synced: int, errors: int, message: string}  $callback
     * @return array{synced: int, errors: int, message: string}
     */
    private function runTracked(string $jobType, callable $callback): array
    {
        $startedAt = microtime(true);
        $intervalKey = $jobType === 'stock_sync' ? 'sync_stock_interval' : 'sync_products_interval';

        $syncJob = SyncJob::query()->firstOrCreate(
            ['job_type' => $jobType],
            [
                'status' => 'idle',
                'interval_minutes' => (int) Setting::getValue($intervalKey, 30),
                'is_active' => true,
            ]
        );

        $syncJob->update([
            'status' => 'running',
            'last_error' => null,
        ]);

        try {
            $result = $callback();
            $duration = round(microtime(true) - $startedAt, 2);

            $syncJob->update([
                'status' => 'idle',
                'last_run' => now(),
                'next_run' => now()->addMinutes((int) $syncJob->interval_minutes),
                'last_error' => ($result['errors'] ?? 0) > 0 ? "{$result['errors']} kayıt hatalı işlendi." : null,
            ]);

            SyncJobLog::query()->create([
                'sync_job_id' => $syncJob->id,
                'status' => ($result['errors'] ?? 0) > 0 ? 'partial' : 'success',
                'message' => $result['message'],
                'synced_count' => $result['synced'] ?? 0,
                'error_count' => $result['errors'] ?? 0,
                'duration' => $duration,
            ]);

            return $result;
        } catch (Throwable $e) {
            $duration = round(microtime(true) - $startedAt, 2);

            $syncJob->update([
                'status' => 'failed',
                'last_run' => now(),
                'last_error' => $e->getMessage(),
            ]);

            SyncJobLog::query()->create([
                'sync_job_id' => $syncJob->id,
                'status' => 'failed',
                'message' => 'Ürün/stok senkronizasyonu başarısız.',
                'synced_count' => 0,
                'error_count' => 1,
                'duration' => $duration,
                'error' => $e->getMessage(),
            ]);

            $this->activityTracker->fail('Senkronizasyon başarısız: '.$e->getMessage(), $e);

            throw $e;
        }
    }
}
