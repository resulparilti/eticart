<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\UyumSoftException;
use App\Models\Setting;
use App\Models\ShopifyOrder;
use App\Models\ShopifyOrderItem;
use App\Models\SyncJob;
use App\Models\SyncJobLog;
use App\Models\UyumSoftProduct;
use App\Support\ShopifyShippingAddress;
use App\Support\UyumSoftOrderLineFormatter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class UyumSoftOrderSyncService
{
    public function __construct(
        private readonly UyumSoftService $uyumSoftService,
        private readonly SyncActivityTracker $activityTracker,
        private readonly OrderLifecycleService $lifecycle
    ) {
    }

    /**
     * Push pending Shopify sales to UyumSoft and pull related invoices.
     *
     * @return array{pushed: int, invoices: int, skipped: int, errors: int, message: string}
     */
    public function sync(int $limit = 50): array
    {
        $startedAt = microtime(true);
        $syncJob = SyncJob::query()->firstOrCreate(
            ['job_type' => 'uyumsoft_order_sync'],
            [
                'status' => 'idle',
                'interval_minutes' => (int) Setting::getValue('sync_orders_interval', Setting::getValue('sync_uyumsoft_orders_interval', 15)),
                'is_active' => true,
            ]
        );

        $syncJob->update([
            'status' => 'running',
            'last_error' => null,
        ]);

        if (! $this->activityTracker->current()) {
            $this->activityTracker->start('uyumsoft_order_sync', 'UyumSoft sipariş / fatura senkronu');
        }
        $this->activityTracker->markRunning('UyumSoft siparişleri kontrol ediliyor…');

        $pushed = 0;
        $invoices = 0;
        $skipped = 0;
        $errors = 0;

        try {
            if (! $this->uyumSoftService->isConfigured()) {
                throw new UyumSoftException('UyumSoft API bilgileri yapılandırılmamış.');
            }

            $pending = ShopifyOrder::query()
                ->with('items')
                ->needsUyumsoftPush()
                ->orderBy('id')
                ->limit($limit)
                ->get();

            $invoiceCandidates = ShopifyOrder::query()
                ->whereNotNull('uyumsoft_order_id')
                ->whereNull('invoice_path')
                ->orderByDesc('id')
                ->limit($limit)
                ->get();

            $this->activityTracker->setTotal($pending->count() + $invoiceCandidates->count());
            $processed = 0;

            foreach ($pending as $order) {
                try {
                    $result = $this->pushOrder($order);
                    if ($result['pushed']) {
                        $pushed++;
                    } else {
                        $skipped++;
                    }
                    if ($result['invoice']) {
                        $invoices++;
                    }
                } catch (Throwable $e) {
                    $errors++;
                    $this->markOrderError($order, $e->getMessage());
                    $this->activityTracker->log('error', $order->order_number.': '.$e->getMessage());
                }
                $processed++;
                $this->activityTracker->progress($processed, $pending->count() + $invoiceCandidates->count());
            }

            foreach ($invoiceCandidates as $order) {
                if ($pending->contains('id', $order->id)) {
                    continue;
                }

                try {
                    if ($this->pullInvoice($order->fresh() ?? $order)) {
                        $invoices++;
                    }
                } catch (Throwable $e) {
                    $errors++;
                    $this->markOrderError($order, $e->getMessage());
                    $this->activityTracker->log('error', $order->order_number.' fatura: '.$e->getMessage());
                }
                $processed++;
                $this->activityTracker->progress($processed, $pending->count() + $invoiceCandidates->count());
            }

            $duration = round(microtime(true) - $startedAt, 2);
            $message = sprintf(
                '%d sipariş UyumSoft’a yazıldı, %d fatura çekildi, %d atlandı%s.',
                $pushed,
                $invoices,
                $skipped,
                $errors ? ", {$errors} hata" : ''
            );

            $syncJob->update([
                'status' => 'idle',
                'last_run' => now(),
                'next_run' => now()->addMinutes((int) $syncJob->interval_minutes),
                'last_error' => $errors > 0 ? "{$errors} kayıt hatalı işlendi." : null,
            ]);

            SyncJobLog::query()->create([
                'sync_job_id' => $syncJob->id,
                'status' => $errors > 0 ? 'partial' : 'success',
                'message' => $message,
                'synced_count' => $pushed + $invoices,
                'error_count' => $errors,
                'duration' => $duration,
            ]);

            $this->activityTracker->complete($message, $pushed + $invoices, $errors, [
                'pushed' => $pushed,
                'invoices' => $invoices,
                'skipped' => $skipped,
            ]);

            return [
                'pushed' => $pushed,
                'invoices' => $invoices,
                'skipped' => $skipped,
                'errors' => $errors,
                'message' => $message,
            ];
        } catch (Throwable $e) {
            $syncJob->update([
                'status' => 'failed',
                'last_run' => now(),
                'last_error' => $e->getMessage(),
            ]);

            SyncJobLog::query()->create([
                'sync_job_id' => $syncJob->id,
                'status' => 'failed',
                'message' => 'UyumSoft sipariş senkronu başarısız.',
                'synced_count' => $pushed,
                'error_count' => $errors + 1,
                'duration' => round(microtime(true) - $startedAt, 2),
                'error' => $e->getMessage(),
            ]);

            $this->activityTracker->fail($e->getMessage(), $e);

            throw $e;
        }
    }

    /**
     * Push a single order and try to attach its UyumSoft invoice.
     *
     * @return array{pushed: bool, invoice: bool, message: string}
     */
    public function syncOrder(ShopifyOrder $order): array
    {
        $order->loadMissing('items');
        $pushed = false;
        $invoice = false;

        if ($order->uyumsoft_order_id === null && $this->shouldPush($order)) {
            $result = $this->pushOrder($order);
            $pushed = $result['pushed'];
            $invoice = $result['invoice'];
        } elseif ($order->invoice_path === null) {
            $invoice = $this->pullInvoice($order);
        }

        $parts = [];
        if ($pushed) {
            $parts[] = 'UyumSoft siparişi oluşturuldu';
        }
        if ($invoice) {
            $parts[] = 'fatura çekildi';
        }
        if ($parts === []) {
            $parts[] = $order->uyumsoft_order_id
                ? 'UyumSoft kaydı zaten var'
                : 'UyumSoft’a gönderilecek yeni satış yok';
        }

        return [
            'pushed' => $pushed,
            'invoice' => $invoice,
            'message' => implode(', ', $parts).'.',
        ];
    }

    /**
     * @return array{pushed: bool, invoice: bool}
     */
    private function pushOrder(ShopifyOrder $order): array
    {
        if (! $this->shouldPush($order)) {
            return ['pushed' => false, 'invoice' => false];
        }

        if ($order->uyumsoft_order_id) {
            return ['pushed' => false, 'invoice' => $this->pullInvoice($order)];
        }

        $existing = $this->uyumSoftService->findSalesOrder($this->erpDocNo($order), $order->order_number);
        if ($existing !== null) {
            $order->update([
                'uyumsoft_order_id' => $this->extractRecordId($existing),
                'uyumsoft_pushed_at' => now(),
                'uyumsoft_last_error' => null,
            ]);

            return ['pushed' => false, 'invoice' => $this->pullInvoice($order->fresh() ?? $order)];
        }

        $payload = $this->buildOrderPayload($order);
        $response = $this->uyumSoftService->createSalesOrder($payload);
        $record = is_array($response['result'] ?? null) ? $response['result'] : $response;
        $remoteId = $this->extractRecordId($record) ?? $this->erpDocNo($order);

        $order->update([
            'uyumsoft_order_id' => $remoteId,
            'uyumsoft_pushed_at' => now(),
            'uyumsoft_last_error' => null,
        ]);

        $this->activityTracker->log('info', $order->order_number.' UyumSoft’a yazıldı ('.$remoteId.').');

        return ['pushed' => true, 'invoice' => $this->pullInvoice($order->fresh() ?? $order)];
    }

    private function pullInvoice(ShopifyOrder $order): bool
    {
        if ($order->hasInvoice()) {
            return false;
        }

        $invoice = null;
        if (filled($order->uyumsoft_invoice_id)) {
            $invoice = [
                'id' => $order->uyumsoft_invoice_id,
                'invoiceId' => $order->uyumsoft_invoice_id,
                'docNo' => $order->uyumsoft_invoice_no,
            ];
        } else {
            $invoice = $this->uyumSoftService->findInvoiceForOrder(
                $this->erpDocNo($order),
                $order->order_number,
                (string) $order->uyumsoft_order_id
            );
        }

        if ($invoice === null) {
            return false;
        }

        $invoiceId = $this->extractRecordId($invoice) ?: $order->uyumsoft_invoice_id;
        $invoiceNo = (string) ($invoice['invoiceNo']
            ?? $invoice['eArchiveNo']
            ?? $invoice['voucherNo']
            ?? $invoice['docNo']
            ?? $order->uyumsoft_invoice_no
            ?? $invoiceId
            ?? '');

        $order->update([
            'uyumsoft_invoice_id' => $invoiceId,
            'uyumsoft_invoice_no' => $invoiceNo !== '' ? $invoiceNo : null,
            'uyumsoft_last_error' => null,
        ]);

        $pdf = $invoiceId ? $this->uyumSoftService->getInvoicePdf($invoiceId) : null;
        if (is_string($pdf) && $pdf !== '') {
            $this->attachInvoicePdf($order, $pdf, $invoiceNo !== '' ? $invoiceNo : $order->order_number);
            $this->activityTracker->log('info', $order->order_number.' faturası UyumSoft’tan alındı.');

            return true;
        }

        $this->activityTracker->log('info', $order->order_number.' için UyumSoft faturası bulundu ama PDF yok.');

        return false;
    }

    private function attachInvoicePdf(ShopifyOrder $order, string $binary, string $invoiceNo): void
    {
        $filename = Str::uuid()->toString().'.pdf';
        $path = 'order-invoices/'.$order->id.'/'.$filename;
        Storage::disk('public')->put($path, $binary);

        if ($order->invoice_path && Storage::disk('public')->exists($order->invoice_path)) {
            Storage::disk('public')->delete($order->invoice_path);
        }

        $order->update([
            'invoice_path' => $path,
            'invoice_original_name' => 'UyumSoft-'.$invoiceNo.'.pdf',
            'invoice_uploaded_at' => now(),
            'invoice_token' => $order->newInvoiceToken(),
            'shopify_needs_push' => true,
        ]);

        $fresh = $order->fresh(['shipments.cargoCompany']) ?? $order;
        $invoiceUrl = $fresh->invoiceUrl();
        if (filled($invoiceUrl)) {
            $fresh->update([
                'notes' => ShopifyOrder::appendInvoiceLine($fresh->notes, (string) $invoiceUrl),
            ]);
            $fresh = $fresh->fresh(['shipments.cargoCompany']) ?? $fresh;
        }

        $this->lifecycle->syncLocalStateToShopify($fresh);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildOrderPayload(ShopifyOrder $order): array
    {
        $entityCode = trim((string) Setting::getValue('uyumsoft_ecommerce_entity_code', ''));
        if ($entityCode === '') {
            throw new UyumSoftException('UyumSoft e-ticaret cari kodu ayarlarda tanımlı olmalı.');
        }

        $lines = [];
        $lineNo = 10;
        $formatter = UyumSoftOrderLineFormatter::fromSettings();
        foreach ($order->items as $item) {
            $resolved = $this->resolveItemMatch($item);
            $itemCode = $resolved['item_code'];
            if ($itemCode === null) {
                continue;
            }

            $line = array_merge([
                'lineNo' => $lineNo,
                'lineType' => 'S',
                'itemCode' => $itemCode,
                'qty' => (float) $item->quantity,
                'qtyPrm' => (float) $item->quantity,
                'unitCode' => $this->uyumSoftService->unitCode(),
                'unitPriceTra' => (float) $item->price,
                'unitPrice' => (float) $item->price,
                'whouseCode' => $this->uyumSoftService->warehouseCode(),
                'vatRate' => 20,
            ], $formatter->extras($item, $resolved['product']));

            $lines[] = $line;
            $lineNo += 10;
        }

        if ($lines === []) {
            throw new UyumSoftException('Siparişte UyumSoft stok kodu eşleşen kalem yok.');
        }

        $locality = ShopifyShippingAddress::fromOrderFields(
            $order->shipping_address,
            $order->shipping_city,
            $order->shipping_province,
            $order->shipping_zip
        );

        return $this->finalizeOrderPayload([
            'coCode' => $this->uyumSoftService->companyCode(),
            'branchCode' => $this->uyumSoftService->branchCode(),
            'whouseCode' => $this->uyumSoftService->warehouseCode(),
            'docNo' => $this->erpDocNo($order),
            'docDate' => optional($order->shopify_created_at)?->format('Y-m-d\TH:i:s') ?: now()->format('Y-m-d\TH:i:s'),
            'entityCode' => $entityCode,
            'curCode' => $order->currency ?: 'TRY',
            'note1' => 'Shopify '.$order->order_number,
            'note2' => (string) $order->shopify_order_id,
            'sourceApp' => 'ETICART',
            'shippingAddress' => $order->shipping_address,
            'city' => $locality['city'] ?: $order->shipping_city,
            'town' => $locality['town'],
            'zipCode' => $locality['zip'] ?: $order->shipping_zip,
            'details' => $lines,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function finalizeOrderPayload(array $payload): array
    {
        $docTraCode = trim((string) Setting::getValue('uyumsoft_doc_tra_code', ''));
        if ($docTraCode !== '') {
            $payload['docTraCode'] = $docTraCode;
        }

        return $payload;
    }

    /**
     * @return array{item_code: ?string, product: ?UyumSoftProduct}
     */
    private function resolveItemMatch(ShopifyOrderItem $item): array
    {
        $sku = trim((string) $item->sku);
        if ($sku === '') {
            return ['item_code' => null, 'product' => null];
        }

        $product = UyumSoftProduct::query()
            ->where(function ($query) use ($sku): void {
                $query->where('sku', $sku)
                    ->orWhere('barcode', $sku)
                    ->orWhere('uyumsoft_id', $sku)
                    ->orWhere('variant_info', 'like', '%'.$sku.'%');
            })
            ->first();

        if ($product) {
            foreach ($product->variant_info['variants'] ?? [] as $variant) {
                if (! is_array($variant)) {
                    continue;
                }
                if (($variant['sku'] ?? null) === $sku || ($variant['barcode'] ?? null) === $sku) {
                    return [
                        'item_code' => (string) ($product->sku ?: $product->uyumsoft_id),
                        'product' => $product,
                    ];
                }
            }

            return [
                'item_code' => (string) ($product->sku ?: $product->uyumsoft_id ?: $sku),
                'product' => $product,
            ];
        }

        return ['item_code' => $sku, 'product' => null];
    }

    private function resolveItemCode(ShopifyOrderItem $item): ?string
    {
        return $this->resolveItemMatch($item)['item_code'];
    }

    private function shouldPush(ShopifyOrder $order): bool
    {
        $status = strtolower((string) $order->fulfillment_status);
        $payment = strtolower((string) $order->payment_status);

        if (in_array($status, ['cancelled', 'restocked'], true)) {
            return false;
        }

        if (in_array($payment, ['refunded', 'voided'], true)) {
            return false;
        }

        return $order->items->isNotEmpty();
    }

    private function erpDocNo(ShopifyOrder $order): string
    {
        $number = ltrim((string) $order->order_number, '#');
        $docNo = 'SH'.$number;

        return substr($docNo, 0, 16);
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function extractRecordId(array $record): ?string
    {
        foreach (['id', 'orderId', 'orderMId', 'invoiceId', 'docId', 'docNo'] as $key) {
            $value = $record[$key] ?? null;
            if (filled($value)) {
                return (string) $value;
            }
        }

        return null;
    }

    private function markOrderError(ShopifyOrder $order, string $message): void
    {
        $order->update(['uyumsoft_last_error' => Str::limit($message, 1000)]);
        Log::channel('stack')->error('UyumSoft order sync item failed', [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'message' => $message,
        ]);
    }
}
