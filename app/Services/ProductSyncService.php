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
            'variant_info' => $this->mergeLocalVariantImages($existing?->variant_info, $data['variant_info'] ?? null),
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
                    $images = $this->shopifyImagePayload($product);
                    if ($images !== []) {
                        $payload['images'] = $images;
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

                if ($shopifyId && in_array(self::OPTION_INFO, $options, true)) {
                    $details = $remote ?: $this->shopifyService->getProductDetails($shopifyId);
                    $this->reconcileShopifyVariants(
                        $product,
                        (string) $shopifyId,
                        is_array($details['variants'] ?? null) ? $details['variants'] : []
                    );
                    $remote = $this->shopifyService->getProductDetails($shopifyId);
                }

                if (($isCreate || in_array(self::OPTION_IMAGES, $options, true)) && $shopifyId) {
                    $remote = $remote ?: $this->shopifyService->getProductDetails($shopifyId);
                    $this->attachShopifyVariantImages($product, $remote);
                    $remote = $this->shopifyService->getProductDetails($shopifyId);
                }
            }

            if ($shopifyId && (
                in_array(self::OPTION_PRICE, $options, true)
                || in_array(self::OPTION_STOCK, $options, true)
                || in_array(self::OPTION_INFO, $options, true)
            )) {
                $details = $remote ?: $this->shopifyService->getProductDetails($shopifyId);
                $remoteVariants = is_array($details['variants'] ?? null) ? $details['variants'] : [];
                $this->syncShopifyVariantPriceAndStock($product, $remoteVariants, $options);
                $remote = $this->shopifyService->getProductDetails($shopifyId);
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
     * Keep locally uploaded variant images when UyumSoft overwrites variant_info.
     *
     * @param  array<string, mixed>|null  $existing
     * @param  array<string, mixed>|null  $incoming
     * @return array<string, mixed>|null
     */
    private function mergeLocalVariantImages(?array $existing, ?array $incoming): ?array
    {
        if ($incoming === null) {
            return $existing;
        }

        $existingVariants = is_array($existing['variants'] ?? null) ? $existing['variants'] : [];
        $incomingVariants = is_array($incoming['variants'] ?? null) ? $incoming['variants'] : [];
        if ($existingVariants === [] || $incomingVariants === []) {
            return $incoming;
        }

        $imagesByKey = [];
        foreach ($existingVariants as $variant) {
            if (! is_array($variant)) {
                continue;
            }
            $image = trim((string) ($variant['image'] ?? $variant['image_url'] ?? ''));
            if ($image === '') {
                continue;
            }
            $imagesByKey[UyumSoftProduct::variantKey($variant)] = $image;
        }

        foreach ($incomingVariants as $index => $variant) {
            if (! is_array($variant)) {
                continue;
            }
            $key = UyumSoftProduct::variantKey($variant);
            if (isset($imagesByKey[$key]) && trim((string) ($variant['image'] ?? '')) === '') {
                $incomingVariants[$index]['image'] = $imagesByKey[$key];
            }
        }

        $incoming['variants'] = $incomingVariants;

        return $incoming;
    }

    /**
     * @return array<int, array{src: string, alt: string}>
     */
    private function shopifyImagePayload(UyumSoftProduct $product): array
    {
        $images = [];
        $seen = [];

        foreach ($product->imageUrls() as $index => $src) {
            $src = trim($src);
            if ($src === '' || isset($seen[$src])) {
                continue;
            }
            $seen[$src] = true;
            $images[] = [
                'src' => $src,
                'alt' => 'eticart:gallery:'.$index,
            ];
        }

        foreach ($product->variantRows() as $row) {
            $src = trim((string) ($row['image'] ?? ''));
            if ($src === '') {
                continue;
            }
            $alt = 'eticart:variant:'.UyumSoftProduct::variantKey($row);
            if (isset($seen[$src])) {
                foreach ($images as $index => $image) {
                    if ($image['src'] === $src && str_starts_with($image['alt'], 'eticart:gallery:')) {
                        $images[$index]['alt'] = $alt;
                    }
                }

                continue;
            }
            $seen[$src] = true;
            $images[] = [
                'src' => $src,
                'alt' => $alt,
            ];
        }

        return $images;
    }

    /**
     * @param  array<string, mixed>  $remote
     */
    private function attachShopifyVariantImages(UyumSoftProduct $product, array $remote): void
    {
        $images = is_array($remote['images'] ?? null) ? $remote['images'] : [];
        $remoteVariants = is_array($remote['variants'] ?? null) ? $remote['variants'] : [];
        if ($images === [] || $remoteVariants === []) {
            return;
        }

        $idByAlt = [];
        $idBySrc = [];
        foreach ($images as $image) {
            if (! is_array($image) || ! isset($image['id'])) {
                continue;
            }
            $id = (string) $image['id'];
            $alt = trim((string) ($image['alt'] ?? ''));
            if ($alt !== '') {
                $idByAlt[$alt] = $id;
            }
            $src = strtolower((string) ($image['src'] ?? ''));
            if ($src !== '') {
                $idBySrc[$src] = $id;
            }
        }

        foreach ($product->variantRows() as $local) {
            $src = trim((string) ($local['image'] ?? ''));
            if ($src === '') {
                continue;
            }

            $match = $this->matchRemoteVariant($remoteVariants, $local);
            $variantId = isset($match['id']) ? (string) $match['id'] : '';
            if ($variantId === '') {
                continue;
            }

            $alt = 'eticart:variant:'.UyumSoftProduct::variantKey($local);
            $imageId = $idByAlt[$alt]
                ?? $idBySrc[strtolower($src)]
                ?? $this->matchShopifyImageIdByFilename($images, $src);

            if ($imageId === null) {
                continue;
            }

            try {
                $this->shopifyService->updateProductVariant($variantId, [
                    'id' => $variantId,
                    'image_id' => (int) $imageId,
                ]);
            } catch (ShopifyException $e) {
                Log::channel('stack')->warning('Variant image attach failed', [
                    'product_id' => $product->id,
                    'sku' => $local['sku'] ?? null,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $images
     */
    private function matchShopifyImageIdByFilename(array $images, string $src): ?string
    {
        $filename = strtolower(basename(parse_url($src, PHP_URL_PATH) ?: $src));
        if ($filename === '') {
            return null;
        }

        foreach ($images as $image) {
            if (! is_array($image) || ! isset($image['id'])) {
                continue;
            }
            $remoteSrc = strtolower((string) ($image['src'] ?? ''));
            if ($remoteSrc !== '' && str_contains($remoteSrc, $filename)) {
                return (string) $image['id'];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function compareAtPriceValue(array $row): ?string
    {
        $compare = $row['compare_at_price'] ?? null;
        if ($compare === null || $compare === '' || (float) $compare <= 0) {
            return null;
        }

        $price = (float) ($row['price'] ?? 0);
        if ((float) $compare <= $price + 0.009) {
            return null;
        }

        return number_format((float) $compare, 2, '.', '');
    }

    /**
     * Build Shopify options + variants from local UyumSoft variant_info.
     *
     * @return array<string, mixed>
     */
    private function buildShopifyVariantPayload(UyumSoftProduct $product, bool $includeInventoryQty): array
    {
        $rows = $product->variantRows();

        if ($rows === []) {
            $variant = [
                'price' => (string) $product->original_price,
                'sku' => $this->shopifyVariantSku([
                    'barcode' => $product->barcode,
                    'sku' => $product->sku,
                ], $product),
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
        $optionDefinitions = $this->buildShopifyOptionDefinitions($product, $rows);
        if ($optionDefinitions !== []) {
            $payload['options'] = $optionDefinitions;
        }

        $variants = [];
        foreach ($rows as $row) {
            $variant = [
                'price' => (string) ($row['price'] ?? $product->original_price),
                'sku' => $this->shopifyVariantSku($row, $product),
                'inventory_management' => 'shopify',
            ];
            $compareAt = $this->compareAtPriceValue($row);
            if ($compareAt !== null) {
                $variant['compare_at_price'] = $compareAt;
            }
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
     * Shopify option sırası variant option1/2/3 ile aynı olmalı.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array{name: string, values: array<int, string>}>
     */
    private function buildShopifyOptionDefinitions(UyumSoftProduct $product, array $rows): array
    {
        $groups = $product->attributeGroups();
        $options = [];

        foreach (['attribute_1', 'attribute_2', 'attribute_3'] as $index => $key) {
            $values = [];
            foreach ($rows as $row) {
                $value = trim((string) ($row[$key] ?? ''));
                if ($value !== '' && ! in_array($value, $values, true)) {
                    $values[] = $value;
                }
            }
            if ($values === []) {
                continue;
            }

            $name = 'Seçenek '.($index + 1);
            foreach ($groups as $group) {
                $groupValues = $group['values'] ?? [];
                if (! is_array($groupValues)) {
                    continue;
                }
                if (array_intersect($values, $groupValues) !== []) {
                    $name = (string) ($group['name'] ?? $name);
                    break;
                }
            }

            $options[] = [
                'name' => $name,
                'values' => $values,
            ];
        }

        return $options;
    }

    /**
     * Mevcut Shopify ürüne eksik varyantları ekler (Default Title -> gerçek seçenekler).
     *
     * @param  array<int, array<string, mixed>>  $remoteVariants
     */
    private function reconcileShopifyVariants(UyumSoftProduct $product, string $shopifyId, array $remoteVariants): void
    {
        $built = $this->buildShopifyVariantPayload($product, true);
        $locals = $built['variants'] ?? [];
        if ($locals === []) {
            return;
        }

        $locals = $this->adaptVariantsToRemoteOptions($locals, $remoteVariants);

        $remoteCount = count($remoteVariants);
        $needsReconcile = count($locals) > $remoteCount
            || (count($locals) > 1 && $this->remoteLooksLikeSingleDefault($remoteVariants));

        if (! $needsReconcile) {
            return;
        }

        $options = $built['options'] ?? [];
        if ($options !== [] && $this->remoteOptionCount($remoteVariants) >= 2) {
            try {
                $this->shopifyService->updateProduct($shopifyId, [
                    'id' => $shopifyId,
                    'options' => $options,
                ]);
                $fresh = $this->shopifyService->getProductDetails($shopifyId);
                if (is_array($fresh['variants'] ?? null)) {
                    $remoteVariants = $fresh['variants'];
                }
            } catch (ShopifyException $e) {
                Log::channel('stack')->warning('Shopify option update failed', [
                    'product_id' => $product->id,
                    'shopify_id' => $shopifyId,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $usedIds = [];
        $created = 0;
        $lastError = null;

        foreach ($locals as $local) {
            $match = $this->matchRemoteVariant($remoteVariants, $local)
                ?? $this->matchRemoteVariantByOptions($remoteVariants, $local);

            if ($match === null) {
                foreach ($remoteVariants as $remote) {
                    if (! is_array($remote)) {
                        continue;
                    }
                    $remoteId = (string) ($remote['id'] ?? '');
                    if ($remoteId === '' || isset($usedIds[$remoteId])) {
                        continue;
                    }
                    if ($this->isDefaultTitleVariant($remote)) {
                        $match = $remote;
                        break;
                    }
                }
            }

            try {
                if ($match !== null && isset($match['id'])) {
                    $variantId = (string) $match['id'];
                    $usedIds[$variantId] = true;
                    $update = $local;
                    unset($update['inventory_quantity']);
                    $update['id'] = $variantId;
                    $updated = $this->shopifyService->updateProductVariant($variantId, $update);
                    foreach ($remoteVariants as $index => $remote) {
                        if ((string) ($remote['id'] ?? '') === $variantId) {
                            $remoteVariants[$index] = array_merge($remote, $update, $updated);
                        }
                    }
                    $this->writeShopifyInventory(
                        $product,
                        $updated !== [] ? array_merge($match, $updated) : $match,
                        (int) ($local['inventory_quantity'] ?? 0)
                    );
                    usleep(400_000);

                    continue;
                }

                $createdPayload = $local;
                unset($createdPayload['inventory_quantity']);
                $createdVariant = $this->shopifyService->createProductVariant($shopifyId, $createdPayload);
                if (! empty($createdVariant['id'])) {
                    $usedIds[(string) $createdVariant['id']] = true;
                    $remoteVariants[] = $createdVariant;
                    $created++;
                    usleep(500_000);
                    $this->writeShopifyInventory(
                        $product,
                        $createdVariant,
                        (int) ($local['inventory_quantity'] ?? 0)
                    );
                }
                usleep(400_000);
            } catch (ShopifyException $e) {
                $lastError = $e;
                Log::channel('stack')->error('Shopify variant reconcile failed', [
                    'product_id' => $product->id,
                    'shopify_id' => $shopifyId,
                    'sku' => $local['sku'] ?? null,
                    'barcode' => $local['barcode'] ?? null,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        if ($created === 0 && count($locals) > count($usedIds) && $lastError instanceof ShopifyException) {
            throw $lastError;
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $remoteVariants
     */
    private function remoteLooksLikeSingleDefault(array $remoteVariants): bool
    {
        return count($remoteVariants) === 1 && $this->isDefaultTitleVariant($remoteVariants[0] ?? []);
    }

    /**
     * @param  array<string, mixed>  $remote
     */
    private function isDefaultTitleVariant(array $remote): bool
    {
        $title = trim((string) ($remote['title'] ?? ''));
        $option1 = trim((string) ($remote['option1'] ?? ''));

        return $title === 'Default Title' || $option1 === 'Default Title';
    }

    /**
     * Tek seçenekli (Default Title) Shopify ürüne option2 göndermek 422 verir.
     * Benzersiz özelliği option1 yap.
     *
     * @param  array<int, array<string, mixed>>  $locals
     * @param  array<int, array<string, mixed>>  $remoteVariants
     * @return array<int, array<string, mixed>>
     */
    private function adaptVariantsToRemoteOptions(array $locals, array $remoteVariants): array
    {
        if ($this->remoteOptionCount($remoteVariants) >= 2) {
            return $locals;
        }

        $option1Unique = $this->uniqueOptionCount($locals, 'option1') > 1;
        if ($option1Unique) {
            return array_map(static function (array $local): array {
                unset($local['option2'], $local['option3']);

                return $local;
            }, $locals);
        }

        return array_map(static function (array $local): array {
            if (! empty($local['option2'])) {
                $local['option1'] = $local['option2'];
            } elseif (! empty($local['option3'])) {
                $local['option1'] = $local['option3'];
            }
            unset($local['option2'], $local['option3']);

            return $local;
        }, $locals);
    }

    /**
     * @param  array<int, array<string, mixed>>  $locals
     */
    private function uniqueOptionCount(array $locals, string $key): int
    {
        $values = [];
        foreach ($locals as $local) {
            $value = trim((string) ($local[$key] ?? ''));
            if ($value !== '') {
                $values[$value] = true;
            }
        }

        return count($values);
    }

    /**
     * @param  array<int, array<string, mixed>>  $remoteVariants
     */
    private function remoteOptionCount(array $remoteVariants): int
    {
        $max = 1;
        foreach ($remoteVariants as $remote) {
            if (! is_array($remote)) {
                continue;
            }
            if (trim((string) ($remote['option3'] ?? '')) !== '') {
                $max = max($max, 3);
            } elseif (trim((string) ($remote['option2'] ?? '')) !== '') {
                $max = max($max, 2);
            }
        }

        return $max;
    }

    /**
     * @param  array<int, array<string, mixed>>  $remoteVariants
     * @param  array<string, mixed>  $local
     * @return array<string, mixed>|null
     */
    private function matchRemoteVariantByOptions(array $remoteVariants, array $local): ?array
    {
        $option1 = (string) ($local['option1'] ?? '');
        if ($option1 === '') {
            return null;
        }

        $option2 = (string) ($local['option2'] ?? '');
        $option3 = (string) ($local['option3'] ?? '');

        foreach ($remoteVariants as $remote) {
            if (! is_array($remote)) {
                continue;
            }
            if (
                (string) ($remote['option1'] ?? '') === $option1
                && (string) ($remote['option2'] ?? '') === $option2
                && (string) ($remote['option3'] ?? '') === $option3
            ) {
                return $remote;
            }
        }

        return null;
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

            if ($variantId && (
                in_array(self::OPTION_PRICE, $options, true)
                || in_array(self::OPTION_INFO, $options, true)
            )) {
                $payload = [
                    'id' => $variantId,
                    'sku' => $this->shopifyVariantSku([
                        'barcode' => $product->barcode,
                        'sku' => $product->sku,
                    ], $product),
                ];
                if (in_array(self::OPTION_PRICE, $options, true)) {
                    $payload['price'] = (string) $product->original_price;
                }
                if ($product->barcode) {
                    $payload['barcode'] = (string) $product->barcode;
                }
                $this->shopifyService->updateProductVariant($variantId, $payload);
            }
            if (in_array(self::OPTION_STOCK, $options, true)) {
                $this->writeShopifyInventory($product, $first !== [] ? $first : [
                    'id' => $variantId,
                    'inventory_item_id' => $inventoryItemId,
                ], (int) $product->stock);
            }

            return;
        }

        foreach ($localRows as $local) {
            $match = $this->matchLocalVariantToRemote($remoteVariants, $local, $product);
            if ($match === null) {
                $this->activityTracker->log(
                    'warning',
                    ($product->sku ?: $product->title).' varyant stoğu eşleşmedi: '.($local['sku'] ?? $local['title'] ?? 'varyant')
                );
                continue;
            }

            $variantId = isset($match['id']) ? (string) $match['id'] : null;

            if ($variantId && (
                in_array(self::OPTION_PRICE, $options, true)
                || in_array(self::OPTION_INFO, $options, true)
            )) {
                $payload = [
                    'id' => $variantId,
                    'sku' => $this->shopifyVariantSku($local, $product),
                ];
                if (in_array(self::OPTION_PRICE, $options, true)) {
                    $payload['price'] = (string) ($local['price'] ?? $product->original_price);
                    $payload['compare_at_price'] = $this->compareAtPriceValue($local);
                }
                if (! empty($local['barcode'])) {
                    $payload['barcode'] = (string) $local['barcode'];
                }
                $this->shopifyService->updateProductVariant($variantId, $payload);
            }

            if (in_array(self::OPTION_STOCK, $options, true)) {
                $this->writeShopifyInventory($product, $match, $this->variantStockQuantity($local));
            }
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $remoteVariants
     * @param  array<string, mixed>  $local
     * @return array<string, mixed>|null
     */
    private function matchLocalVariantToRemote(array $remoteVariants, array $local, UyumSoftProduct $product): ?array
    {
        $enriched = [
            'sku' => $this->shopifyVariantSku($local, $product),
            'barcode' => $local['barcode'] ?? $local['barkod'] ?? null,
            'option1' => $local['attribute_1'] ?? $local['option1'] ?? '',
            'option2' => $local['attribute_2'] ?? $local['option2'] ?? '',
            'option3' => $local['attribute_3'] ?? $local['option3'] ?? '',
        ];

        $adapted = $this->adaptVariantsToRemoteOptions([$enriched], $remoteVariants)[0] ?? $enriched;

        return $this->matchRemoteVariant($remoteVariants, $adapted)
            ?? $this->matchRemoteVariant($remoteVariants, $local)
            ?? $this->matchRemoteVariantByOptions($remoteVariants, $adapted);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function variantStockQuantity(array $row): int
    {
        $raw = $row['stock'] ?? $row['quantity'] ?? $row['qty'] ?? $row['inventory_quantity'] ?? null;
        if ($raw === null || $raw === '' || $raw === '-') {
            return 0;
        }

        return max(0, (int) $raw);
    }

    /**
     * Shopify REST variant inventory_quantity yazmaz; Inventory API gerekir.
     *
     * @param  array<string, mixed>  $remoteVariant
     */
    private function writeShopifyInventory(UyumSoftProduct $product, array $remoteVariant, int $quantity): void
    {
        $inventoryItemId = isset($remoteVariant['inventory_item_id'])
            ? (string) $remoteVariant['inventory_item_id']
            : '';
        $variantId = isset($remoteVariant['id']) ? (string) $remoteVariant['id'] : '';

        if ($inventoryItemId === '' && $variantId !== '') {
            try {
                $fresh = $this->shopifyService->getProductVariant($variantId);
                $inventoryItemId = isset($fresh['inventory_item_id']) ? (string) $fresh['inventory_item_id'] : '';
            } catch (ShopifyException $e) {
                Log::channel('stack')->warning('Variant inventory item okunamadı', [
                    'product_id' => $product->id,
                    'variant_id' => $variantId,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        if ($inventoryItemId === '') {
            $this->activityTracker->log(
                'warning',
                ($product->sku ?: $product->title).': Shopify inventory_item_id yok, stok yazılamadı.'
            );

            return;
        }

        try {
            $this->shopifyService->updateInventory($inventoryItemId, $quantity);
        } catch (ShopifyException $e) {
            usleep(500_000);
            try {
                $this->shopifyService->updateInventory($inventoryItemId, $quantity);
            } catch (ShopifyException $retry) {
                $this->activityTracker->log(
                    'error',
                    ($product->sku ?: $product->title).' stok yazılamadı: '.$retry->getMessage()
                );
                Log::channel('stack')->warning('Variant inventory update failed', [
                    'product_id' => $product->id,
                    'sku' => $remoteVariant['sku'] ?? null,
                    'inventory_item_id' => $inventoryItemId,
                    'quantity' => $quantity,
                    'message' => $retry->getMessage(),
                ]);
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
            $remoteBarcode = (string) ($remote['barcode'] ?? '');
            if ($barcode !== '' && $remoteBarcode === $barcode) {
                return $remote;
            }
        }

        foreach ($remoteVariants as $remote) {
            if (! is_array($remote)) {
                continue;
            }
            $remoteSku = (string) ($remote['sku'] ?? '');
            if ($barcode !== '' && $remoteSku === $barcode) {
                return $remote;
            }
            if ($sku !== '' && $remoteSku === $sku) {
                return $remote;
            }
        }

        return null;
    }

    /**
     * Shopify varyant SKU alanı barkoddur; yoksa yerel stok koduna düşer.
     *
     * @param  array<string, mixed>  $row
     */
    private function shopifyVariantSku(array $row, UyumSoftProduct $product): string
    {
        $barcode = trim((string) ($row['barcode'] ?? ''));
        if ($barcode !== '') {
            return $barcode;
        }

        $sku = trim((string) ($row['sku'] ?? ''));
        if ($sku !== '') {
            return $sku;
        }

        $productBarcode = trim((string) ($product->barcode ?? ''));
        if ($productBarcode !== '') {
            return $productBarcode;
        }

        return (string) ($product->sku ?: $product->uyumsoft_id);
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
     * Shopify'daki görsel, meta alan ve koleksiyon güncellemelerini lokale çeker.
     *
     * @param  array<int, int>  $uyumSoftProductIds
     * @return array{synced: int, skipped: int, errors: int, message: string}
     */
    public function pullShopifyUpdates(array $uyumSoftProductIds): array
    {
        if (! $this->shopifyService->isConfigured()) {
            throw new ShopifyException('Shopify API ayarları eksik.');
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $uyumSoftProductIds))));
        $products = UyumSoftProduct::query()
            ->with('shopifyProduct')
            ->whereIn('id', $ids)
            ->get();

        $synced = 0;
        $skipped = 0;
        $errors = 0;
        $total = $products->count();

        if ($this->activityTracker->current()) {
            $this->activityTracker->setTotal($total);
            $this->activityTracker->markRunning('Shopify ürün güncellemeleri çekiliyor…');
        }

        $processed = 0;
        foreach ($products as $product) {
            $processed++;
            try {
                $result = $this->pullShopifyUpdatesForProduct($product);
                if ($result['skipped']) {
                    $skipped++;
                    if ($this->activityTracker->current()) {
                        $this->activityTracker->log('warning', ($product->sku ?: $product->title).': '.$result['message']);
                    }
                } else {
                    $synced++;
                    if ($this->activityTracker->current()) {
                        $this->activityTracker->log('success', ($product->sku ?: $product->title).' Shopify’dan güncellendi.');
                    }
                }
            } catch (Throwable $e) {
                $errors++;
                Log::channel('stack')->error('Shopify product update pull failed', [
                    'product_id' => $product->id,
                    'message' => $e->getMessage(),
                ]);
                if ($this->activityTracker->current()) {
                    $this->activityTracker->log('error', ($product->sku ?: $product->title).': '.$e->getMessage());
                }
            }

            if ($this->activityTracker->current()) {
                $this->activityTracker->progress($processed, $total);
            }
        }

        return [
            'synced' => $synced,
            'skipped' => $skipped,
            'errors' => $errors,
            'message' => "{$synced} ürün Shopify’dan güncellendi"
                .($skipped > 0 ? ", {$skipped} atlandı" : '')
                .($errors > 0 ? ", {$errors} hata" : '')
                .'.',
        ];
    }

    /**
     * @return array{skipped: bool, message: string}
     */
    public function pullShopifyUpdatesForProduct(UyumSoftProduct $product): array
    {
        $product->loadMissing('shopifyProduct');
        $shopifyId = trim((string) ($product->shopify_id ?: $product->shopifyProduct?->shopify_product_id ?: ''));
        if ($shopifyId === '') {
            return ['skipped' => true, 'message' => 'Shopify ürün kimliği yok.'];
        }

        $remote = $this->shopifyService->getProductDetails($shopifyId);
        if ($remote === [] || empty($remote['id'])) {
            return ['skipped' => true, 'message' => 'Shopify’da ürün bulunamadı.'];
        }

        $metafields = $this->shopifyService->getProductMetafields($shopifyId);
        $collections = $this->shopifyService->getProductCollections($shopifyId);
        $this->upsertShopifyRemoteProduct($remote, $metafields, $collections);
        $this->applyShopifyMediaToUyumSoft($product->fresh(['shopifyProduct']) ?? $product, $remote);

        return ['skipped' => false, 'message' => 'ok'];
    }

    /**
     * @param  array<string, mixed>  $remote
     * @param  array<int, array<string, mixed>>|null  $metafields
     * @param  array<int, array<string, mixed>>|null  $collections
     * @return array{rows: int, linked: int}
     */
    private function upsertShopifyRemoteProduct(
        array $remote,
        ?array $metafields = null,
        ?array $collections = null
    ): array
    {
        $productId = (string) ($remote['id'] ?? '');
        if ($productId === '') {
            return ['rows' => 0, 'linked' => 0];
        }

        $variants = $remote['variants'] ?? [];
        if (! is_array($variants) || $variants === []) {
            $variants = [[]];
        }

        $imageIndex = $this->shopifyImageIndex($remote);

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
                'stock' => (int) ($variant['inventory_quantity'] ?? $variant['stock'] ?? 0),
                'barcode' => filled($variant['barcode'] ?? null) ? (string) $variant['barcode'] : null,
                'inventory_item_id' => isset($variant['inventory_item_id'])
                    ? (string) $variant['inventory_item_id']
                    : null,
                'image' => $this->shopifyVariantImageSrc($variant, $imageIndex),
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
                'image' => null,
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

        $images = $imageIndex['gallery'];
        $payload = [
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
        ];
        if ($metafields !== null) {
            $payload['metafields'] = $metafields;
        }
        if ($collections !== null) {
            $payload['collections'] = $collections;
        }

        $shopifyProduct = ShopifyProduct::query()->updateOrCreate(
            ['shopify_product_id' => $productId],
            $payload
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

    /**
     * @param  array<string, mixed>  $remote
     * @return array{gallery: array<int, array{src: string, alt: mixed, position: int}>, by_id: array<string, string>, by_variant: array<string, string>}
     */
    private function shopifyImageIndex(array $remote): array
    {
        $gallery = [];
        $byId = [];
        $byVariant = [];

        foreach ($remote['images'] ?? [] as $image) {
            if (! is_array($image)) {
                continue;
            }

            $src = trim((string) ($image['src'] ?? ''));
            if ($src === '') {
                continue;
            }

            $gallery[] = [
                'src' => $src,
                'alt' => $image['alt'] ?? null,
                'position' => (int) ($image['position'] ?? 0),
            ];

            if (isset($image['id'])) {
                $byId[(string) $image['id']] = $src;
            }

            foreach ($image['variant_ids'] ?? [] as $variantId) {
                $byVariant[(string) $variantId] = $src;
            }
        }

        usort($gallery, static fn (array $a, array $b): int => ($a['position'] ?? 0) <=> ($b['position'] ?? 0));

        return [
            'gallery' => array_values($gallery),
            'by_id' => $byId,
            'by_variant' => $byVariant,
        ];
    }

    /**
     * @param  array<string, mixed>  $variant
     * @param  array{gallery: array<int, array{src: string, alt: mixed, position: int}>, by_id: array<string, string>, by_variant: array<string, string>}  $index
     */
    private function shopifyVariantImageSrc(array $variant, array $index): ?string
    {
        $variantId = isset($variant['id']) ? (string) $variant['id'] : '';
        if ($variantId !== '' && isset($index['by_variant'][$variantId])) {
            return $index['by_variant'][$variantId];
        }

        $imageId = isset($variant['image_id']) ? (string) $variant['image_id'] : '';
        if ($imageId !== '' && isset($index['by_id'][$imageId])) {
            return $index['by_id'][$imageId];
        }

        $direct = trim((string) ($variant['image'] ?? $variant['image_url'] ?? ''));

        return $direct !== '' ? $direct : null;
    }

    private function isShopifyHostedImage(string $url): bool
    {
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));

        return $host !== '' && (str_contains($host, 'shopify.com') || str_contains($host, 'shopifycdn.com'));
    }

    /**
     * @param  array<string, mixed>  $remote
     */
    private function applyShopifyMediaToUyumSoft(UyumSoftProduct $product, array $remote): void
    {
        $index = $this->shopifyImageIndex($remote);
        $gallery = array_values(array_filter(array_map(
            static fn (array $image): string => trim((string) ($image['src'] ?? '')),
            $index['gallery']
        )));

        $imagesBySku = [];
        $imagesByBarcode = [];
        foreach ($remote['variants'] ?? [] as $variant) {
            if (! is_array($variant)) {
                continue;
            }
            $src = $this->shopifyVariantImageSrc($variant, $index);
            if ($src === null) {
                continue;
            }
            $sku = trim((string) ($variant['sku'] ?? ''));
            if ($sku !== '') {
                $imagesBySku[$sku] = $src;
            }
            $barcode = trim((string) ($variant['barcode'] ?? ''));
            if ($barcode !== '') {
                $imagesByBarcode[$barcode] = $src;
            }
        }

        $variantInfo = is_array($product->variant_info) ? $product->variant_info : [];
        $localVariants = is_array($variantInfo['variants'] ?? null) ? $variantInfo['variants'] : [];
        foreach ($localVariants as $i => $variant) {
            if (! is_array($variant)) {
                continue;
            }
            $barcode = trim((string) ($variant['barcode'] ?? $variant['barkod'] ?? ''));
            $sku = trim((string) ($variant['sku'] ?? $variant['stockCode'] ?? $variant['code'] ?? ''));
            $src = ($barcode !== '' ? ($imagesByBarcode[$barcode] ?? null) : null)
                ?? ($sku !== '' ? ($imagesBySku[$sku] ?? null) : null);
            $current = trim((string) ($localVariants[$i]['image'] ?? ''));
            if ($src) {
                $localVariants[$i]['image'] = $src;
            } elseif ($current !== '' && $this->isShopifyHostedImage($current)) {
                $localVariants[$i]['image'] = null;
            }
        }
        if ($localVariants !== []) {
            $variantInfo['variants'] = $localVariants;
        }

        $values = [
            'shopify_id' => (string) ($remote['id'] ?? $product->shopify_id),
            'synced_to_shopify' => true,
            'shopify_synced_at' => now(),
            'last_sync' => now(),
            // Shopify kaynağı: silinen görseller de yansısın (boş galeri dahil).
            'images' => $gallery,
        ];
        if ($variantInfo !== []) {
            $values['variant_info'] = $variantInfo;
        }

        $product->update($values);
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
