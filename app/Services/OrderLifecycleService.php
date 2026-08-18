<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\SendTrackingInfo;
use App\Models\AdminNotification;
use App\Models\Setting;
use App\Models\Shipment;
use App\Models\ShopifyOrder;
use App\Support\StatusLabels;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

class OrderLifecycleService
{
    public function __construct(
        private readonly ShopifyService $shopifyService,
        private readonly AdminNotificationService $notifications,
        private readonly SyncActivityTracker $activityTracker
    ) {
    }

    /**
     * Kargo API kaydı oluştu: sipariş hazırlanıyor (Shopify'a da yazılır).
     */
    public function markPreparing(ShopifyOrder $order, Shipment $shipment): void
    {
        if (! in_array((string) $order->fulfillment_status, ['fulfilled', 'cancelled'], true)) {
            $order->update([
                'fulfillment_status' => 'preparing',
                'shopify_needs_push' => true,
            ]);
        } else {
            $order->markNeedsShopifyPush();
        }

        if ($shipment->status !== Shipment::STATUS_SHIPPED) {
            $shipment->update([
                'status' => Shipment::STATUS_PENDING,
                'shipped_at' => null,
            ]);
        }

        $this->syncLocalStateToShopify($order->fresh(['shipments.cargoCompany']), false);

        $this->notifications->notify(
            AdminNotification::TYPE_ORDER_PREPARING,
            $order->order_number.' hazırlanıyor',
            'Sipariş kargo servisine gönderildi. Kargo firması henüz kabul etmedi.',
            route('orders.show', $order),
            $order
        );
    }

    /**
     * Kargo firması gönderiyi sistemine işledi: kargoya verildi + takip Shopify'a.
     */
    public function markShipped(ShopifyOrder $order, Shipment $shipment, array $trackingInfo = []): void
    {
        $trackingNumber = (string) ($trackingInfo['tracking_number'] ?? $shipment->tracking_number ?? '');
        $trackingUrl = (string) ($trackingInfo['tracking_url'] ?? $shipment->tracking_url ?? '');

        $shipment->update([
            'status' => Shipment::STATUS_SHIPPED,
            'tracking_number' => $trackingNumber !== '' ? $trackingNumber : $shipment->tracking_number,
            'tracking_url' => $trackingUrl !== '' ? $trackingUrl : $shipment->tracking_url,
            'shipped_at' => $shipment->shipped_at ?? now(),
        ]);

        // Halka açık takip no geldiyse cargo_key'i koru; tracking_number güncellenir.
        if (! empty($trackingInfo['tracking_ready'])
            && filled($trackingInfo['tracking_number'] ?? null)
            && filled($shipment->cargo_key)
            && (string) $trackingInfo['tracking_number'] !== (string) $shipment->cargo_key
        ) {
            $shipment->update([
                'tracking_number' => (string) $trackingInfo['tracking_number'],
                'tracking_url' => (string) ($trackingInfo['tracking_url'] ?? $shipment->tracking_url),
            ]);
        }

        if ($order->fulfillment_status === 'delivered') {
            return;
        }

        $order->update([
            'fulfillment_status' => 'fulfilled',
            'shopify_needs_push' => true,
        ]);

        $this->syncLocalStateToShopify($order->fresh(['shipments.cargoCompany']), false);

        $companyName = $shipment->cargoCompany?->name ?: 'Kargo';
        $this->notifications->notify(
            AdminNotification::TYPE_ORDER_SHIPPED,
            $order->order_number.' kargoya verildi',
            trim($companyName.' · '.($trackingNumber !== '' ? $trackingNumber : 'Takip no bekleniyor')),
            route('orders.show', $order),
            $order
        );

        if ((string) Setting::getValue('auto_send_tracking', '0') === '1') {
            SendTrackingInfo::dispatch($shipment->id);
        }
    }

    public function markDelivered(ShopifyOrder $order, Shipment $shipment, ?CarbonInterface $deliveredAt = null): void
    {
        $alreadyDelivered = $shipment->status === Shipment::STATUS_DELIVERED
            && $order->fulfillment_status === 'delivered';

        $shipment->update([
            'status' => Shipment::STATUS_DELIVERED,
            'delivered_at' => $deliveredAt ?? $shipment->delivered_at ?? now(),
        ]);

        $order->update([
            'fulfillment_status' => 'delivered',
            'shopify_needs_push' => true,
        ]);

        $this->syncLocalStateToShopify($order->fresh(['shipments.cargoCompany']), false);

        if ($alreadyDelivered) {
            return;
        }

        $this->notifications->notify(
            AdminNotification::TYPE_ORDER_DELIVERED,
            $order->order_number.' teslim edildi',
            'Kargo teslim olarak işaretlendi.',
            route('orders.show', $order),
            $order
        );
    }

    public function markCancelled(ShopifyOrder $order, ?string $reason = null): void
    {
        $order->update([
            'fulfillment_status' => 'cancelled',
            'shopify_needs_push' => true,
        ]);

        $this->notifications->notify(
            AdminNotification::TYPE_ORDER_CANCELLED,
            $order->order_number.' iptal edildi',
            $reason ?: 'Sipariş Shopify üzerinde iptal edildi.',
            route('orders.show', $order),
            $order
        );
    }

    /**
     * Lokal durum / fatura / kargo bilgilerini Shopify sipariş detayına yazar.
     *
     * @return array{success: bool, skipped: bool, actions: array<int, string>, message: string}
     */
    public function syncLocalStateToShopify(ShopifyOrder $order, bool $startActivity = true): array
    {
        $order->loadMissing(['shipments.cargoCompany']);
        $ownActivity = false;

        if ($startActivity && ! $this->activityTracker->current()) {
            $this->activityTracker->start('order_push', $order->order_number.' Shopify güncelleme', 1, [
                'order_id' => $order->id,
                'shopify_order_id' => $order->shopify_order_id,
            ]);
            $this->activityTracker->markRunning($order->order_number.' Shopify siparişine yazılıyor…');
            $ownActivity = true;
        }

        if (! $this->shopifyService->isConfigured() || blank($order->shopify_order_id)) {
            $message = 'Shopify yapılandırılmamış veya sipariş numarası yok; push atlandı.';
            $this->activityTracker->log('warning', $order->order_number.': '.$message);
            if ($ownActivity) {
                $this->activityTracker->complete($message, 0, 0, ['skipped' => true]);
            }

            return [
                'success' => false,
                'skipped' => true,
                'actions' => [],
                'message' => $message,
            ];
        }

        $actions = [];
        $fulfillError = null;

        try {
            $status = (string) $order->fulfillment_status;
            $shipment = $order->latestCargoShipment() ?? $order->latestActiveShipment();

            if (in_array($status, ['fulfilled', 'delivered'], true)) {
            try {
                $trackingNumber = (string) ($shipment?->publicTrackingNumber() ?? '');
                $trackingUrl = $trackingNumber !== '' ? (string) ($shipment?->tracking_url ?? '') : '';
                $fulfilled = $this->shopifyService->fulfillOrderWithTracking(
                    (string) $order->shopify_order_id,
                    $trackingNumber,
                    $trackingUrl,
                    (string) ($shipment?->cargoCompany?->name ?? 'Kargo')
                );
                $actions[] = ! empty($fulfilled['already_fulfilled'])
                    ? 'fulfillment_existing'
                    : 'fulfillment';
                if ($trackingNumber === '') {
                    $this->activityTracker->log(
                        'info',
                        $order->order_number.': Shopify fulfillment (takip no henüz yok — şube kabulü bekleniyor).'
                    );
                    $actions[] = 'tracking_pending';
                } else {
                    $this->activityTracker->log(
                        'info',
                        $order->order_number.': Shopify’a takip no yazıldı ('.$trackingNumber.').'
                    );
                }
            } catch (Throwable $e) {
                    $fulfillError = $e->getMessage();
                    $this->activityTracker->log('error', $order->order_number.' Shopify fulfillment: '.$fulfillError);
                    Log::channel('stack')->warning('Shopify fulfillment push failed', [
                        'order_id' => $order->id,
                        'message' => $fulfillError,
                    ]);
                }
            } else {
                try {
                    $reverted = $this->shopifyService->revertShopifyFulfillment(
                        (string) $order->shopify_order_id,
                        $status
                    );
                    $actions[] = (string) ($reverted['action'] ?? 'revert');
                } catch (Throwable $e) {
                    $fulfillError = $e->getMessage();
                    $this->activityTracker->log('error', $order->order_number.' Shopify durum geri alma: '.$fulfillError);
                    Log::channel('stack')->warning('Shopify fulfillment revert failed', [
                        'order_id' => $order->id,
                        'status' => $status,
                        'message' => $fulfillError,
                    ]);
                }
            }

            $this->shopifyService->updateOrderWorkflow(
                (string) $order->shopify_order_id,
                $this->workflowPayload($order, $shipment)
            );
            $actions[] = 'workflow';

            if ($order->hasInvoice()) {
                $actions[] = 'invoice';
            }

            $ok = $fulfillError === null;
            $order->update([
                'shopify_needs_push' => ! $ok,
                'shopify_pushed_at' => $ok ? now() : $order->shopify_pushed_at,
            ]);

            $message = $ok
                ? $order->order_number.' Shopify siparişine yansıtıldı ('.implode(', ', $actions).').'
                : $order->order_number.' Shopify durum güncellemesi başarısız: '.$fulfillError;

            $this->activityTracker->log($ok ? 'success' : 'error', $message, [
                'actions' => $actions,
                'status' => $status,
            ]);
            Log::channel('stack')->info('Shopify order push completed', [
                'order_id' => $order->id,
                'shopify_order_id' => $order->shopify_order_id,
                'actions' => $actions,
                'status' => $status,
                'fulfill_error' => $fulfillError,
            ]);

            if ($ownActivity) {
                if ($ok) {
                    $this->activityTracker->complete($message, 1, 0, ['actions' => $actions]);
                } else {
                    $this->activityTracker->fail($message);
                }
            }

            return [
                'success' => $ok,
                'skipped' => false,
                'actions' => $actions,
                'message' => $message,
            ];
        } catch (Throwable $e) {
            $order->update(['shopify_needs_push' => true]);
            $message = $order->order_number.' Shopify güncellemesi başarısız: '.$e->getMessage();
            $this->activityTracker->log('error', $message);
            Log::channel('stack')->warning('Shopify order push failed', [
                'order_id' => $order->id,
                'message' => $e->getMessage(),
            ]);

            if ($ownActivity) {
                $this->activityTracker->fail($message, $e);
            }

            return [
                'success' => false,
                'skipped' => false,
                'actions' => $actions,
                'message' => $message,
            ];
        }
    }

    /**
     * @return array{status_label: string, add_tags: array<int, string>, remove_tags: array<int, string>, tracking_number?: string, cargo_company?: string, invoice_url: string, note: string}
     */
    private function workflowPayload(ShopifyOrder $order, ?Shipment $shipment): array
    {
        $status = (string) $order->fulfillment_status;
        $payload = [
            'status_label' => StatusLabels::fulfillment($status),
            'invoice_url' => (string) ($order->invoiceUrl() ?? ''),
            'note' => (string) ($order->notes ?? ''),
            'add_tags' => ['EtiCart'],
            'remove_tags' => [],
        ];

        if ($status === 'preparing') {
            $payload['add_tags'][] = 'Hazırlanıyor';
            $payload['remove_tags'] = ['Kargoya Verildi', 'Teslim Edildi', 'İptal', 'Stoğa İade'];
        } elseif ($status === 'fulfilled') {
            $payload['add_tags'][] = 'Kargoya Verildi';
            $payload['remove_tags'] = ['Hazırlanıyor', 'Teslim Edildi', 'İptal', 'Stoğa İade'];
        } elseif ($status === 'delivered') {
            $payload['add_tags'][] = 'Teslim Edildi';
            $payload['remove_tags'] = ['Hazırlanıyor', 'İptal', 'Stoğa İade'];
        } elseif ($status === 'cancelled') {
            $payload['add_tags'][] = 'İptal';
            $payload['remove_tags'] = ['Hazırlanıyor', 'Kargoya Verildi', 'Teslim Edildi', 'Stoğa İade'];
        } elseif ($status === 'restocked') {
            $payload['add_tags'][] = 'Stoğa İade';
            $payload['remove_tags'] = ['Hazırlanıyor', 'Kargoya Verildi', 'Teslim Edildi', 'İptal'];
        } else {
            $payload['remove_tags'] = ['Hazırlanıyor', 'Kargoya Verildi', 'Teslim Edildi', 'İptal', 'Stoğa İade'];
        }

        if (in_array($status, ['fulfilled', 'delivered'], true) && $shipment) {
            $publicTracking = $shipment->publicTrackingNumber();
            if ($publicTracking !== null) {
                $payload['tracking_number'] = $publicTracking;
                $payload['cargo_company'] = (string) ($shipment->cargoCompany?->name ?? '');
            } elseif ($shipment->cargoCompany) {
                $payload['cargo_company'] = (string) $shipment->cargoCompany->name;
            }
        }

        return $payload;
    }
}
