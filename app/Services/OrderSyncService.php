<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AdminNotification;
use App\Models\Setting;
use App\Support\StatusLabels;
use App\Models\Shipment;
use App\Models\ShopifyOrder;
use App\Models\ShopifyOrderArchive;
use App\Models\ShopifyOrderItem;
use App\Models\SyncJob;
use App\Models\SyncJobLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class OrderSyncService
{
    public function __construct(
        private readonly ShopifyService $shopifyService,
        private readonly CustomerSyncService $customerSyncService,
        private readonly SyncActivityTracker $activityTracker,
        private readonly AdminNotificationService $notifications,
        private readonly OrderLifecycleService $lifecycle
    ) {
    }

    /**
     * Sync orders from Shopify into local database.
     *
     * @return array{synced: int, errors: int, message: string}
     */
    public function sync(int $limit = 50, string $status = 'any'): array
    {
        $startedAt = microtime(true);
        $syncJob = SyncJob::query()->firstOrCreate(
            ['job_type' => 'order_sync'],
            [
                'status' => 'idle',
                'interval_minutes' => (int) Setting::getValue('sync_orders_interval', 15),
                'is_active' => true,
            ]
        );

        $syncJob->update([
            'status' => 'running',
            'last_error' => null,
        ]);

        $synced = 0;
        $errors = 0;
        $redacted = 0;
        $created = 0;
        $cancelled = 0;
        $skipped = 0;
        $updated = 0;
        $archived = 0;
        $pushed = 0;
        $message = 'Sipariş senkronizasyonu tamamlandı.';

        if (! $this->activityTracker->current()) {
            $this->activityTracker->start('order_sync', 'Shopify sipariş tarama');
        }
        $this->activityTracker->markRunning('Shopify siparişleri kontrol ediliyor…');

        try {
            $pageInfo = null;
            $pages = 0;
            $maxPages = 40;
            $orders = [];
            $remoteIds = [];

            do {
                $pages++;
                $result = $this->shopifyService->getOrders($limit, $status, $pageInfo);
                $batch = $result['orders'] ?? [];
                $orders = array_merge($orders, $batch);
                foreach ($batch as $row) {
                    if (isset($row['id'])) {
                        $remoteIds[(string) $row['id']] = true;
                    }
                }
                $pageInfo = $result['next_page_info'] ?? null;
                $this->activityTracker->log('info', "Sayfa {$pages}: ".count($batch).' sipariş.');
            } while ($pageInfo && $pages < $maxPages);

            $returnMap = $this->shopifyService->getReturnStatusesByOrderId();
            foreach ($orders as $index => $orderPayload) {
                $id = (string) ($orderPayload['id'] ?? '');
                if ($id !== '' && isset($returnMap[$id])) {
                    $orders[$index] = $this->applyReturnMeta($orderPayload, $returnMap[$id]);
                }
            }

            foreach ($returnMap as $shopifyId => $meta) {
                if (isset($remoteIds[(string) $shopifyId])) {
                    continue;
                }
                try {
                    $remote = $this->shopifyService->findOrder((string) $shopifyId);
                    if ($remote === null) {
                        continue;
                    }
                    $orders[] = $this->applyReturnMeta($remote, $meta);
                    $remoteIds[(string) $shopifyId] = true;
                    $this->activityTracker->log('info', 'İade siparişi eklendi: #'.$shopifyId);
                } catch (Throwable $e) {
                    $errors++;
                    $this->activityTracker->log('warning', 'İade siparişi çekilemedi: '.$e->getMessage());
                }
            }

            $this->activityTracker->setTotal(max(count($orders), 1));

            foreach ($orders as $orderPayload) {
                try {
                    if ($this->shopifyService->isCustomerDataRedacted($orderPayload)) {
                        $redacted++;
                    }

                    $meta = $this->upsertOrder($orderPayload);
                    $synced++;
                    if ($meta['created']) {
                        $created++;
                    } elseif ($meta['skipped'] ?? false) {
                        $skipped++;
                    } else {
                        $updated++;
                    }
                    if ($meta['cancelled']) {
                        $cancelled++;
                    }
                    $this->activityTracker->progress($synced, count($orders), "{$synced}/".count($orders).' sipariş');
                } catch (Throwable $e) {
                    $errors++;
                    $this->activityTracker->log('error', 'Sipariş işlenemedi: '.$e->getMessage());
                    Log::channel('stack')->error('Order sync item failed', [
                        'shopify_order_id' => $orderPayload['id'] ?? null,
                        'message' => $e->getMessage(),
                    ]);
                }
            }

            $this->activityTracker->log('info', 'Shopify’da silinen siparişler kontrol ediliyor…');
            $removed = $this->archiveMissingRemoteOrders(array_keys($remoteIds));
            $archived = $removed['archived'];
            $errors += $removed['errors'];
            $synced += $removed['verified'];

            $this->activityTracker->log('info', 'Lokal güncellemeler Shopify\'a yazılıyor…');
            $pushResult = $this->pushPendingToShopify();
            $pushed = $pushResult['pushed'];
            $archived += $pushResult['archived'];
            $errors += $pushResult['errors'];

            $duration = round(microtime(true) - $startedAt, 2);
            $interval = (int) $syncJob->interval_minutes;

            $syncJob->update([
                'status' => 'idle',
                'last_run' => now(),
                'next_run' => now()->addMinutes($interval),
                'last_error' => $errors > 0 ? "{$errors} sipariş hatalı işlendi." : null,
            ]);

            $logMessage = $message
                ." {$synced} kontrol edildi"
                .($created ? ", {$created} yeni" : '')
                .($updated ? ", {$updated} güncellendi" : '')
                .($skipped ? ", {$skipped} mevcut bırakıldı" : '')
                .($archived ? ", {$archived} Shopify’dan silindi (arşiv)" : '')
                .($pushed ? ", {$pushed} Shopify'a yazıldı" : '')
                .($cancelled ? ", {$cancelled} iptal" : '')
                .($redacted > 0 ? ", {$redacted} PII sansürlü" : '')
                .'.';

            SyncJobLog::query()->create([
                'sync_job_id' => $syncJob->id,
                'status' => $errors > 0 ? 'partial' : 'success',
                'message' => $logMessage,
                'synced_count' => $synced,
                'error_count' => $errors,
                'duration' => $duration,
            ]);

            $this->activityTracker->complete($logMessage, $synced, $errors, [
                'created' => $created,
                'updated' => $updated,
                'skipped' => $skipped,
                'archived' => $archived,
                'pushed' => $pushed,
                'cancelled' => $cancelled,
                'redacted' => $redacted,
                'pages' => $pages ?? 1,
            ]);

            $summary = "{$synced} sipariş senkronize edildi"
                .($created ? ", {$created} yeni" : '')
                .($updated ? ", {$updated} güncellendi" : '')
                .($skipped ? ", {$skipped} mevcut bırakıldı" : '')
                .($archived ? ", {$archived} silinen arşivlendi" : '')
                .($pushed ? ", {$pushed} Shopify'a yazıldı" : '')
                .($errors ? ", {$errors} hata" : '').'.';
            if ($redacted > 0) {
                $summary .= " Uyarı: {$redacted} siparişte müşteri adı/e-posta/telefon/adres Shopify tarafından gizlenmiş (Protected Customer Data). "
                    .'Shopify uygulamasında read_customers + read_orders kapsamları ve Level 2 müşteri verisi erişimi gerekir; Admin’den oluşturulan custom app’te mağaza planı (Basic) Level 2’yi kısıtlayabilir.';
            }

            return [
                'synced' => $synced,
                'errors' => $errors,
                'redacted' => $redacted,
                'created' => $created,
                'updated' => $updated,
                'skipped' => $skipped,
                'archived' => $archived,
                'pushed' => $pushed,
                'message' => $summary,
            ];
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
                'message' => 'Sipariş senkronizasyonu başarısız.',
                'synced_count' => $synced,
                'error_count' => $errors + 1,
                'duration' => $duration,
                'error' => $e->getMessage(),
            ]);

            $this->activityTracker->fail('Sipariş taraması başarısız: '.$e->getMessage(), $e);

            Log::channel('stack')->error('Order sync failed', [
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Push local status / invoice / cargo updates to Shopify.
     *
     * @return array{pushed: int, errors: int, archived: int}
     */
    public function pushPendingToShopify(int $limit = 100): array
    {
        $orders = ShopifyOrder::query()
            ->needsShopifyPush()
            ->with(['shipments.cargoCompany'])
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $pushed = 0;
        $errors = 0;
        $archived = 0;

        foreach ($orders as $order) {
            $result = $this->lifecycle->syncLocalStateToShopify($order, false);
            if ($result['not_found'] ?? false) {
                $this->archiveDeletedOrder($order, 'shopify_not_found_on_push');
                $archived++;
                continue;
            }
            if ($result['success']) {
                $pushed++;
                continue;
            }
            if (! ($result['skipped'] ?? false)) {
                $errors++;
            }
        }

        return [
            'pushed' => $pushed,
            'errors' => $errors,
            'archived' => $archived,
        ];
    }

    /**
     * Bidirectional sync for a single local order.
     *
     * @return array{success: bool, message: string, pushed: bool}
     */
    public function syncOne(ShopifyOrder $order): array
    {
        if (! $this->activityTracker->current()) {
            $this->activityTracker->start('order_sync', 'Sipariş '.$order->order_number.' senkron', 2, [
                'order_id' => $order->id,
                'shopify_order_id' => $order->shopify_order_id,
            ]);
        }
        $this->activityTracker->markRunning($order->order_number.' Shopify ile eşleştiriliyor…');

        $errors = 0;

        if ($this->shopifyService->isConfigured() && filled($order->shopify_order_id)) {
            try {
                    $remote = $this->shopifyService->findOrder((string) $order->shopify_order_id);
                    if ($remote === null) {
                        $this->archiveDeletedOrder($order, 'shopify_deleted');
                        $message = $order->order_number.' Shopify’da silinmiş; yerel kayıt arşivlendi.';
                        $this->activityTracker->complete($message, 1, 0, ['archived' => true]);

                        return [
                            'success' => true,
                            'message' => $message,
                            'pushed' => false,
                            'archived' => true,
                        ];
                    }

                    $returns = $this->shopifyService->getOrderReturns((string) $order->shopify_order_id);
                    if ($returns !== []) {
                        $remote['returns'] = $returns;
                    }

                    $this->upsertOrder($remote);
                $order = $order->fresh(['shipments.cargoCompany']) ?? $order;
                $this->activityTracker->log('info', $order->order_number.' Shopify kaydı çekildi.');
            } catch (Throwable $e) {
                $errors++;
                $this->activityTracker->log('error', 'Shopify çekme hatası: '.$e->getMessage());
                Log::channel('stack')->warning('Single order pull failed', [
                    'order_id' => $order->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $order = $order->fresh(['shipments.cargoCompany']) ?? $order;
        if (in_array((string) $order->fulfillment_status, ['refunded', 'partially_refunded', 'restocked'], true)) {
            $order->forceFill(['shopify_needs_push' => false])->save();
            $message = $order->order_number.' iade durumu Shopify’dan alındı.';
            $this->activityTracker->complete($message, 1, $errors);

            return [
                'success' => $errors === 0,
                'message' => $message,
                'pushed' => false,
            ];
        }

        $this->activityTracker->progress(1, 2, 'Lokal durum Shopify\'a yazılıyor…');
        $order->markNeedsShopifyPush();
        $push = $this->lifecycle->syncLocalStateToShopify($order->fresh(['shipments.cargoCompany']), false);

        if ($push['not_found'] ?? false) {
            $this->archiveDeletedOrder($order, 'shopify_not_found_on_push');
            $message = $order->order_number.' Shopify’da bulunamadı; yerel kayıt arşivlendi.';
            $this->activityTracker->complete($message, 1, 0, ['archived' => true]);

            return [
                'success' => true,
                'message' => $message,
                'pushed' => false,
                'archived' => true,
            ];
        }

        if (! $push['success'] && ! ($push['skipped'] ?? false)) {
            $errors++;
        }

        $message = $order->order_number.' senkron tamamlandı'
            .($push['success'] ? ' — Shopify güncellendi.' : ($push['skipped'] ? ' — Shopify atlandı.' : ' — Shopify yazımı başarısız.'));

        if ($errors > 0) {
            $this->activityTracker->complete($message, 1, $errors, [
                'pushed' => $push['success'],
            ]);
        } else {
            $this->activityTracker->complete($message, 1, 0, [
                'pushed' => $push['success'],
            ]);
        }

        Log::channel('stack')->info('Single order sync completed', [
            'order_id' => $order->id,
            'pushed' => $push['success'],
            'errors' => $errors,
        ]);

        return [
            'success' => $errors === 0,
            'message' => $push['success']
                ? $message
                : ($push['message'] !== '' ? $push['message'] : $message),
            'pushed' => $push['success'],
        ];
    }

    /**
     * Upsert a Shopify order payload into local storage.
     *
     * @param  array<string, mixed>  $payload
     * @return array{order: ShopifyOrder, created: bool, cancelled: bool, skipped: bool}
     */
    public function upsertOrder(array $payload): array
    {
        return DB::transaction(function () use ($payload) {
            $existing = ShopifyOrder::withTrashed()
                ->where('shopify_order_id', (string) $payload['id'])
                ->first();

            if ($existing?->trashed()) {
                $existing->restore();
            }

            $wasCancelled = $existing && $existing->fulfillment_status === 'cancelled';

            if ($existing) {
                return $this->touchExistingOrder($existing, $payload, $wasCancelled);
            }
            $customerInfo = $this->extractCustomerInfo($payload);
            $customer = null;
            $hasIdentity = filled($customerInfo['email'])
                || filled($customerInfo['phone'])
                || (filled($customerInfo['name']) && $customerInfo['name'] !== 'Misafir')
                || filled(data_get($payload, 'customer.id'));

            if ($hasIdentity && ! $this->shopifyService->isCustomerDataRedacted($payload)) {
                try {
                    $remoteCustomer = is_array($payload['customer'] ?? null) ? $payload['customer'] : [];
                    if ($remoteCustomer !== []) {
                        $customer = $this->customerSyncService->upsertFromShopifyPayload(array_merge($remoteCustomer, [
                            'email' => $remoteCustomer['email'] ?? $customerInfo['email'],
                            'phone' => $remoteCustomer['phone'] ?? $customerInfo['phone'],
                        ]));
                    }

                    $tempOrder = new ShopifyOrder([
                        'customer_name' => $customerInfo['name'],
                        'customer_email' => $customerInfo['email'],
                        'customer_phone' => $customerInfo['phone'],
                        'shipping_address' => $customerInfo['address'],
                        'shipping_city' => $customerInfo['city'],
                        'currency' => (string) ($payload['currency'] ?? 'TRY'),
                        'shopify_created_at' => isset($payload['created_at'])
                            ? Carbon::parse($payload['created_at'])
                            : null,
                    ]);
                    $customer = $this->customerSyncService->upsertFromOrder($tempOrder, $payload) ?? $customer;
                } catch (Throwable $e) {
                    Log::channel('stack')->warning('Order customer upsert skipped', [
                        'order_id' => $payload['id'] ?? null,
                        'message' => $e->getMessage(),
                    ]);
                }
            }

            $incomingStatus = $payload['fulfillment_status'] ?? 'unfulfilled';
            $shopifyCancelled = filled($payload['cancelled_at'] ?? null) || filled($payload['cancel_reason'] ?? null);
            if ($shopifyCancelled) {
                $incomingStatus = 'cancelled';
            } elseif ($refundStatus = $this->detectShopifyRefund($payload)) {
                $incomingStatus = $refundStatus;
            }

            $fulfillmentStatus = $this->resolveFulfillmentStatus($existing, (string) $incomingStatus);

            $order = ShopifyOrder::query()->updateOrCreate(
                ['shopify_order_id' => (string) $payload['id']],
                [
                    'order_number' => (string) ($payload['name'] ?? $payload['order_number'] ?? $payload['id']),
                    'customer_id' => $customer?->id,
                    'customer_name' => $customerInfo['name'],
                    'customer_email' => $customerInfo['email'],
                    'customer_phone' => $customerInfo['phone'],
                    'shipping_address' => $customerInfo['address'],
                    'shipping_city' => $customerInfo['city'],
                    'shipping_province' => $customerInfo['province'],
                    'shipping_zip' => $customerInfo['zip'],
                    'total_price' => (float) ($payload['total_price'] ?? 0),
                    'currency' => (string) ($payload['currency'] ?? 'TRY'),
                    'payment_status' => $payload['financial_status'] ?? null,
                    'fulfillment_status' => $fulfillmentStatus,
                    'order_items' => $payload['line_items'] ?? [],
                    'notes' => $payload['note'] ?? null,
                    'shopify_created_at' => isset($payload['created_at'])
                        ? Carbon::parse($payload['created_at'])
                        : null,
                    'synced_at' => now(),
                ]
            );

            $order->items()->delete();

            foreach ($payload['line_items'] ?? [] as $lineItem) {
                ShopifyOrderItem::query()->create([
                    'shopify_order_id' => $order->id,
                    'shopify_line_item_id' => isset($lineItem['id']) ? (string) $lineItem['id'] : null,
                    'product_title' => (string) ($lineItem['title'] ?? 'Ürün'),
                    'variant_title' => $lineItem['variant_title'] ?? null,
                    'sku' => $lineItem['sku'] ?? null,
                    'barcode' => $lineItem['barcode'] ?? null,
                    'quantity' => (int) ($lineItem['quantity'] ?? 1),
                    'price' => (float) ($lineItem['price'] ?? 0),
                ]);
            }

            if ((string) Setting::getValue('auto_create_shipment', '0') === '1') {
                $this->ensureDraftShipment($order);
            }

            $fresh = $order->fresh(['items', 'shipments']);
            $created = $existing === null;
            $justCancelled = $fulfillmentStatus === 'cancelled' && ! $wasCancelled;

            $this->rememberContentHash($fresh ?? $order, $created);

            if ($created) {
                $this->notifications->notify(
                    AdminNotification::TYPE_ORDER_CREATED,
                    $fresh->order_number.' yeni sipariş',
                    trim($fresh->customer_name.' · ₺'.number_format((float) $fresh->total_price, 2)),
                    route('orders.show', $fresh),
                    $fresh
                );
            }

            $this->notifyRefundIfNeeded($fresh, $payload, $existing?->fulfillment_status ?? '', (string) ($existing?->payment_status ?? ''));

            if ($justCancelled) {
                $this->lifecycle->markCancelled($fresh, (string) ($payload['cancel_reason'] ?? 'Shopify iptali'));
            }

            if ($customer?->wasRecentlyCreated) {
                $this->notifications->notify(
                    AdminNotification::TYPE_CUSTOMER_CREATED,
                    'Yeni müşteri: '.($customer->full_name ?: $customer->email),
                    $customer->email ?: $customer->phone,
                    route('customers.show', $customer),
                    $customer
                );
            }

            return [
                'order' => $fresh,
                'created' => $created,
                'cancelled' => $justCancelled,
                'skipped' => false,
            ];
        });
    }

    /**
     * Existing local orders: Shopify content (items, totals, address) is applied;
     * local fulfillment / notes / invoice stay and are pushed back later.
     *
     * @param  array<string, mixed>  $payload
     * @return array{order: ShopifyOrder, created: bool, cancelled: bool, skipped: bool}
     */
    private function touchExistingOrder(ShopifyOrder $existing, array $payload, bool $wasCancelled): array
    {
        $shopifyCancelled = filled($payload['cancelled_at'] ?? null) || filled($payload['cancel_reason'] ?? null);
        $justCancelled = $shopifyCancelled && ! $wasCancelled;

        $incomingStatus = (string) ($payload['fulfillment_status'] ?? 'unfulfilled');
        if ($shopifyCancelled) {
            $incomingStatus = 'cancelled';
        } elseif ($refundStatus = $this->detectShopifyRefund($payload)) {
            $incomingStatus = $refundStatus;
        }

        $previousFulfillment = (string) $existing->fulfillment_status;
        $previousPayment = (string) ($existing->payment_status ?? '');
        $mappedStatus = $this->mapInboundShopifyStatus($existing, $incomingStatus);
        $statusChanged = $mappedStatus !== $previousFulfillment;

        $customerInfo = $this->extractCustomerInfo($payload);
        $redacted = $this->shopifyService->isCustomerDataRedacted($payload);

        $content = [
            'synced_at' => now(),
            'total_price' => (float) ($payload['total_price'] ?? $existing->total_price),
            'currency' => (string) ($payload['currency'] ?? $existing->currency ?? 'TRY'),
            'order_items' => $payload['line_items'] ?? $existing->order_items,
        ];

        if (! $existing->shopify_needs_push || $this->detectShopifyRefund($payload)) {
            $content['payment_status'] = $payload['financial_status'] ?? $existing->payment_status;
        }

        if ($this->detectShopifyRefund($payload)) {
            $content['shopify_needs_push'] = false;
        }

        if ($statusChanged) {
            $content['fulfillment_status'] = $mappedStatus;
        }

        if (! $redacted) {
            if (filled($customerInfo['name']) && $customerInfo['name'] !== 'Misafir') {
                $content['customer_name'] = $customerInfo['name'];
            }
            if (filled($customerInfo['email'])) {
                $content['customer_email'] = $customerInfo['email'];
            }
            if (filled($customerInfo['phone'])) {
                $content['customer_phone'] = $customerInfo['phone'];
            }
            if (filled($customerInfo['address'])) {
                $content['shipping_address'] = $customerInfo['address'];
            }
            if (filled($customerInfo['city'])) {
                $content['shipping_city'] = $customerInfo['city'];
            }
            if (filled($customerInfo['province'])) {
                $content['shipping_province'] = $customerInfo['province'];
            }
            if (filled($customerInfo['zip'])) {
                $content['shipping_zip'] = $customerInfo['zip'];
            }
        }

        $existing->update($content);
        $itemsChanged = $this->replaceLineItems($existing, is_array($payload['line_items'] ?? null) ? $payload['line_items'] : []);

        if ($justCancelled) {
            $this->lifecycle->markCancelled(
                $existing->fresh() ?? $existing,
                (string) ($payload['cancel_reason'] ?? 'Shopify iptali')
            );
        } elseif ($statusChanged && $mappedStatus === 'preparing') {
            $this->activityTracker->log('info', "{$existing->order_number}: Shopify durumu → Hazırlanıyor");
        } elseif ($statusChanged && $mappedStatus === 'fulfilled') {
            $this->activityTracker->log('info', "{$existing->order_number}: Shopify durumu → Kargoya verildi");
        }

        $fresh = $existing->fresh(['items']) ?? $existing;
        $this->rememberContentHash($fresh, false);
        $this->notifyRefundIfNeeded($fresh, $payload, $previousFulfillment, $previousPayment);

        $skipped = ! $statusChanged && ! $justCancelled && ! $itemsChanged;

        return [
            'order' => $fresh,
            'created' => false,
            'cancelled' => $justCancelled,
            'skipped' => $skipped,
        ];
    }

    /**
     * Shopify → panel durum eşlemesi (mevcut siparişler için).
     */
    private function mapInboundShopifyStatus(ShopifyOrder $existing, string $incoming): string
    {
        $incoming = strtolower(trim($incoming !== '' ? $incoming : 'unfulfilled'));
        $current = (string) $existing->fulfillment_status;

        if ($incoming === 'cancelled') {
            return 'cancelled';
        }

        if (in_array($incoming, ['refunded', 'partially_refunded', 'restocked'], true)) {
            return $incoming === 'restocked' ? 'refunded' : $incoming;
        }

        if ($current === 'delivered' && in_array($incoming, ['fulfilled', 'unfulfilled', 'null', ''], true)) {
            return 'delivered';
        }

        if ($incoming === 'fulfilled') {
            return 'fulfilled';
        }

        if ($incoming === 'partial') {
            return 'preparing';
        }

        if (in_array($current, ['preparing', 'fulfilled', 'delivered'], true) && in_array($incoming, ['unfulfilled', 'null', ''], true)) {
            return $current;
        }

        return $incoming === 'unfulfilled' ? 'unfulfilled' : $current;
    }

    private function resolveFulfillmentStatus(?ShopifyOrder $existing, string $incoming): string
    {
        $incoming = $incoming !== '' ? $incoming : 'unfulfilled';

        if ($incoming === 'cancelled') {
            return $incoming;
        }

        if (in_array($incoming, ['refunded', 'partially_refunded', 'restocked'], true)) {
            return $incoming === 'restocked' ? 'refunded' : $incoming;
        }

        if ($existing && $existing->fulfillment_status === 'delivered'
            && in_array($incoming, ['fulfilled', 'unfulfilled', 'null', ''], true)
        ) {
            return 'delivered';
        }

        if ($incoming === 'fulfilled') {
            return $incoming;
        }

        if ($existing && in_array($existing->fulfillment_status, ['preparing', 'fulfilled', 'delivered'], true)
            && in_array($incoming, ['unfulfilled', 'null', ''], true)
        ) {
            return (string) $existing->fulfillment_status;
        }

        return $incoming;
    }

    /**
     * Shopify iade / iade talebi: financial_status, refunds listesi veya return_status.
     *
     * @param  array<string, mixed>  $payload
     */
    private function detectShopifyRefund(array $payload): ?string
    {
        $financial = strtolower(trim((string) ($payload['financial_status'] ?? '')));
        if ($financial === 'refunded') {
            return 'refunded';
        }
        if ($financial === 'partially_refunded') {
            return 'partially_refunded';
        }

        $returnStatus = strtolower(trim((string) ($payload['return_status'] ?? '')));
        if (in_array($returnStatus, ['in_progress', 'inspection', 'return_requested', 'requested', 'open'], true)) {
            return 'partially_refunded';
        }
        if (in_array($returnStatus, ['returned', 'closed', 'completed'], true)) {
            return $this->payloadHasRefunds($payload) ? 'refunded' : 'partially_refunded';
        }

        $fromReturns = $this->statusFromReturnRecords($payload);
        if ($fromReturns !== null) {
            return $fromReturns;
        }

        if ($this->payloadHasRefunds($payload)) {
            return $financial === 'paid' ? 'partially_refunded' : 'refunded';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function payloadHasRefunds(array $payload): bool
    {
        $refunds = $payload['refunds'] ?? null;
        if (! is_array($refunds) || $refunds === []) {
            return false;
        }

        foreach ($refunds as $refund) {
            if (is_array($refund) && (($refund['id'] ?? null) || ($refund['created_at'] ?? null))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function statusFromReturnRecords(array $payload): ?string
    {
        $returns = $payload['returns'] ?? null;
        if (! is_array($returns) || $returns === []) {
            return null;
        }

        $open = false;
        $closed = false;
        foreach ($returns as $return) {
            if (! is_array($return)) {
                continue;
            }
            $status = strtolower((string) ($return['status'] ?? ''));
            if (in_array($status, ['requested', 'open', 'in_progress', 'inspection', 'return_requested'], true)) {
                $open = true;
            }
            if (in_array($status, ['closed', 'completed', 'returned'], true)) {
                $closed = true;
            }
        }

        if ($open) {
            return 'partially_refunded';
        }

        return $closed ? 'refunded' : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array{return_status?: string, financial_status?: string}  $meta
     * @return array<string, mixed>
     */
    private function applyReturnMeta(array $payload, array $meta): array
    {
        if (filled($meta['return_status'] ?? null)) {
            $payload['return_status'] = strtolower((string) $meta['return_status']);
        }

        $financial = strtolower((string) ($meta['financial_status'] ?? ''));
        if (in_array($financial, ['refunded', 'partially_refunded'], true)) {
            $payload['financial_status'] = $financial;
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function notifyRefundIfNeeded(ShopifyOrder $order, array $payload, string $previousFulfillment, string $previousPayment): void
    {
        $refundStatus = $this->detectShopifyRefund($payload);
        if ($refundStatus === null) {
            return;
        }

        $alreadyRefunded = in_array($previousFulfillment, ['refunded', 'partially_refunded', 'restocked'], true)
            || in_array($previousPayment, ['refunded', 'partially_refunded'], true);
        if ($alreadyRefunded && $refundStatus === 'partially_refunded') {
            return;
        }

        $isFull = $refundStatus === 'refunded';
        $type = $isFull
            ? AdminNotification::TYPE_ORDER_REFUNDED
            : AdminNotification::TYPE_ORDER_REFUND_REQUESTED;
        $title = $isFull
            ? $order->order_number.' iade edildi'
            : $order->order_number.' iade talebi';
        $message = $isFull
            ? 'Shopify’da iade tamamlandı. Önceki durum: '.StatusLabels::fulfillment($previousFulfillment)
            : 'Shopify’da iade talebi / kısmi iade oluştu.';

        $this->notifications->notify(
            $type,
            $title,
            $message,
            route('orders.show', $order),
            $order
        );
    }

    /**
     * Extract customer fields from Shopify order payload with fallbacks.
     *
     * @param  array<string, mixed>  $payload
     * @return array{name: string, email: ?string, phone: ?string, address: ?string, city: ?string, province: ?string, zip: ?string}
     */
    public function extractCustomerInfo(array $payload): array
    {
        $shipping = is_array($payload['shipping_address'] ?? null) ? $payload['shipping_address'] : [];
        $billing = is_array($payload['billing_address'] ?? null) ? $payload['billing_address'] : [];
        $customer = is_array($payload['customer'] ?? null) ? $payload['customer'] : [];
        $defaultAddress = is_array($customer['default_address'] ?? null) ? $customer['default_address'] : [];

        $name = $this->firstFilled([
            $this->personName($shipping),
            $this->personName($billing),
            $this->personName($customer),
            $this->personName($defaultAddress),
            $payload['email'] ?? null,
            $customer['email'] ?? null,
            'Misafir',
        ]);

        $email = $this->firstFilled([
            $payload['email'] ?? null,
            $payload['contact_email'] ?? null,
            $customer['email'] ?? null,
        ]);

        $phone = $this->normalizePhone($this->firstFilled([
            $payload['phone'] ?? null,
            $shipping['phone'] ?? null,
            $billing['phone'] ?? null,
            $customer['phone'] ?? null,
            $defaultAddress['phone'] ?? null,
        ]));

        $locality = \App\Support\ShopifyShippingAddress::fromShopify(
            $shipping,
            $billing,
            $defaultAddress,
            is_array($payload['note_attributes'] ?? null) ? $payload['note_attributes'] : []
        );

        return [
            'name' => (string) $name,
            'email' => $email ? (string) $email : null,
            'phone' => $phone,
            'address' => $locality['street'] !== '' ? $locality['street'] : null,
            'city' => $locality['town'] !== '' ? $locality['town'] : ($locality['city'] !== '' ? $locality['city'] : null),
            'province' => $locality['province'] !== '' ? $locality['province'] : null,
            'zip' => $locality['zip'] !== '' ? $locality['zip'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $person
     */
    private function personName(array $person): ?string
    {
        if (filled($person['name'] ?? null)) {
            return trim((string) $person['name']);
        }

        $combined = trim(($person['first_name'] ?? '').' '.($person['last_name'] ?? ''));

        return $combined !== '' ? $combined : null;
    }

    /**
     * @param  array<int, mixed>  $values
     */
    private function firstFilled(array $values): mixed
    {
        foreach ($values as $value) {
            if (filled($value)) {
                return $value;
            }
        }

        return null;
    }

    private function normalizePhone(mixed $phone): ?string
    {
        if (! filled($phone)) {
            return null;
        }

        return preg_replace('/\s+/', '', (string) $phone) ?: null;
    }

    /**
     * Create a pending shipment draft for an order when missing.
     */
    public function ensureDraftShipment(ShopifyOrder $order): Shipment
    {
        $existing = $order->shipments()->latest()->first();

        if ($existing) {
            return $existing;
        }

        $locality = $order->resolveShippingLocality();

        return $order->shipments()->create([
            'order_number' => $order->order_number,
            'status' => Shipment::STATUS_PENDING,
            'receiver_name' => $order->customer_name,
            'receiver_phone' => $order->customer_phone,
            'receiver_address' => $locality['street'] !== '' ? $locality['street'] : $order->shipping_address,
            'receiver_city' => $locality['city'] !== '' ? $locality['city'] : $order->shipping_city,
            'amount' => $order->total_price,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $lineItems
     */
    private function replaceLineItems(ShopifyOrder $order, array $lineItems): bool
    {
        $current = $order->items()->orderBy('id')->get()->map(static fn (ShopifyOrderItem $item): array => [
            'line' => (string) $item->shopify_line_item_id,
            'sku' => (string) $item->sku,
            'qty' => (int) $item->quantity,
            'price' => number_format((float) $item->price, 2, '.', ''),
            'title' => (string) $item->product_title,
        ])->values()->all();

        $incoming = collect($lineItems)->map(static fn (array $lineItem): array => [
            'line' => isset($lineItem['id']) ? (string) $lineItem['id'] : '',
            'sku' => (string) ($lineItem['sku'] ?? ''),
            'qty' => (int) ($lineItem['quantity'] ?? 1),
            'price' => number_format((float) ($lineItem['price'] ?? 0), 2, '.', ''),
            'title' => (string) ($lineItem['title'] ?? 'Ürün'),
        ])->values()->all();

        if ($current === $incoming) {
            return false;
        }

        $order->items()->delete();
        foreach ($lineItems as $lineItem) {
            ShopifyOrderItem::query()->create([
                'shopify_order_id' => $order->id,
                'shopify_line_item_id' => isset($lineItem['id']) ? (string) $lineItem['id'] : null,
                'product_title' => (string) ($lineItem['title'] ?? 'Ürün'),
                'variant_title' => $lineItem['variant_title'] ?? null,
                'sku' => $lineItem['sku'] ?? null,
                'barcode' => $lineItem['barcode'] ?? null,
                'quantity' => (int) ($lineItem['quantity'] ?? 1),
                'price' => (float) ($lineItem['price'] ?? 0),
            ]);
        }

        $this->activityTracker->log('info', $order->order_number.': sipariş içeriği Shopify’dan güncellendi.');

        return true;
    }

    private function rememberContentHash(ShopifyOrder $order, bool $created): void
    {
        $fresh = $order->fresh(['items']) ?? $order;
        $hash = $fresh->contentHash();
        $previous = $fresh->shopify_content_hash;
        $updates = ['shopify_content_hash' => $hash];

        if ($created || blank($previous)) {
            $fresh->forceFill($updates)->save();

            return;
        }

        if ($previous === $hash) {
            if ($fresh->uyumsoft_invoice_locked && filled($fresh->uyumsoft_content_hash) && $fresh->uyumsoft_content_hash === $hash) {
                $updates['uyumsoft_invoice_locked'] = false;
                $updates['uyumsoft_needs_update'] = false;
                $fresh->forceFill($updates)->save();
            }

            return;
        }

        if (filled($fresh->uyumsoft_order_id)) {
            if ($fresh->uyumsoftInvoiceLocked()) {
                $updates['uyumsoft_invoice_locked'] = true;
                $updates['uyumsoft_needs_update'] = false;
                $this->activityTracker->log(
                    'warning',
                    $fresh->order_number.': Shopify içeriği değişti ancak UyumSoft faturası kesilmiş; ERP siparişi güncellenmedi.'
                );
            } else {
                $updates['uyumsoft_needs_update'] = true;
                $this->activityTracker->log('info', $fresh->order_number.': içerik değişti, UyumSoft güncellemesi kuyruğa alınacak.');
            }
        }

        $fresh->forceFill($updates)->save();
    }

    /**
     * @param  array<int, string>  $remoteIds
     * @return array{archived: int, errors: int, verified: int}
     */
    private function archiveMissingRemoteOrders(array $remoteIds): array
    {
        $remoteLookup = array_fill_keys($remoteIds, true);
        $locals = ShopifyOrder::query()
            ->whereNotNull('shopify_order_id')
            ->orderBy('id')
            ->get();

        $archived = 0;
        $errors = 0;
        $verified = 0;

        foreach ($locals as $order) {
            $shopifyId = (string) $order->shopify_order_id;
            if (isset($remoteLookup[$shopifyId])) {
                continue;
            }

            try {
                $remote = $this->shopifyService->findOrder($shopifyId);
                $verified++;
                if ($remote !== null) {
                    $this->upsertOrder($remote);
                    continue;
                }

                $this->archiveDeletedOrder($order, 'shopify_deleted');
                $archived++;
            } catch (Throwable $e) {
                $errors++;
                $this->activityTracker->log('error', $order->order_number.' silinme kontrolü başarısız: '.$e->getMessage());
                Log::channel('stack')->warning('Order delete reconcile failed', [
                    'order_id' => $order->id,
                    'shopify_order_id' => $shopifyId,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return [
            'archived' => $archived,
            'errors' => $errors,
            'verified' => $verified,
        ];
    }

    public function archiveDeletedOrder(ShopifyOrder $order, string $reason = 'shopify_deleted'): ShopifyOrderArchive
    {
        $order->loadMissing(['items', 'shipments']);

        $archive = ShopifyOrderArchive::query()->create([
            'local_order_id' => $order->id,
            'shopify_order_id' => (string) $order->shopify_order_id,
            'order_number' => (string) $order->order_number,
            'customer_name' => $order->customer_name,
            'customer_email' => $order->customer_email,
            'customer_phone' => $order->customer_phone,
            'total_price' => $order->total_price,
            'currency' => $order->currency,
            'payment_status' => $order->payment_status,
            'fulfillment_status' => $order->fulfillment_status,
            'reason' => $reason,
            'snapshot' => [
                'order' => $order->attributesToArray(),
                'items' => $order->items->toArray(),
                'shipments' => $order->shipments->toArray(),
            ],
            'archived_at' => now(),
        ]);

        $summary = sprintf(
            '%s Shopify’dan silindi ve arşivlendi (%s, ₺%s, %s).',
            $order->order_number,
            $order->customer_name ?: 'müşteri yok',
            number_format((float) $order->total_price, 2, ',', '.'),
            $archive->reasonLabel()
        );

        $this->activityTracker->log('warning', $summary, [
            'archive_id' => $archive->id,
            'shopify_order_id' => $order->shopify_order_id,
            'order_number' => $order->order_number,
            'customer_name' => $order->customer_name,
            'total_price' => $order->total_price,
            'items' => $order->items->count(),
        ]);

        Log::channel('stack')->info('Shopify order archived after remote delete', [
            'archive_id' => $archive->id,
            'order_id' => $order->id,
            'shopify_order_id' => $order->shopify_order_id,
            'order_number' => $order->order_number,
            'reason' => $reason,
            'snapshot' => $archive->snapshot,
        ]);

        $order->delete();

        return $archive;
    }
}
