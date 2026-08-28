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
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class UyumSoftOrderSyncService
{
    public function __construct(
        private readonly UyumSoftService $uyumSoftService,
        private readonly UyumSoftEInvoiceService $eInvoiceService,
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

            $contentUpdates = ShopifyOrder::query()
                ->with('items')
                ->needsUyumsoftUpdate()
                ->orderBy('id')
                ->limit($limit)
                ->get()
                ->reject(fn (ShopifyOrder $order): bool => $pending->contains('id', $order->id));

            $invoiceCandidates = ShopifyOrder::query()
                ->whereNotNull('uyumsoft_order_id')
                ->whereNull('invoice_path')
                ->whereNull('uyumsoft_einvoice_uuid')
                ->orderByDesc('id')
                ->limit($limit)
                ->get();

            $this->activityTracker->setTotal($pending->count() + $contentUpdates->count() + $invoiceCandidates->count());
            $processed = 0;

            foreach ($pending->concat($contentUpdates) as $order) {
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
                $this->activityTracker->progress($processed, $pending->count() + $contentUpdates->count() + $invoiceCandidates->count());
            }

            foreach ($invoiceCandidates as $order) {
                if ($pending->contains('id', $order->id) || $contentUpdates->contains('id', $order->id)) {
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
                $this->activityTracker->progress($processed, $pending->count() + $contentUpdates->count() + $invoiceCandidates->count());
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

        if (! $this->activityTracker->current()) {
            $this->activityTracker->start(
                'uyumsoft_order_sync',
                $order->order_number.' UyumSoft gönder / fatura çek',
                2,
                [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'manual' => true,
                    'source' => 'order_detail',
                ],
                Auth::id()
            );
        }

        $this->activityTracker->markRunning($order->order_number.' UyumSoft işleniyor…');

        try {
            if (! $this->uyumSoftService->isConfigured()) {
                throw new UyumSoftException('UyumSoft API bilgileri yapılandırılmamış.');
            }

            $pushed = false;
            $invoice = false;

            if ($order->uyumsoftInvoiceLocked() && $order->uyumsoft_needs_update) {
                $this->markInvoiceLockedWarning($order);
                $this->activityTracker->log(
                    'warning',
                    $order->order_number.': fatura kesildiği için UyumSoft siparişi güncellenmedi.'
                );
            }

            if ($order->uyumsoft_order_id === null && $this->shouldPush($order)) {
                $this->activityTracker->log('info', 'UyumSoft sipariş oluşturma deneniyor…');
                $result = $this->pushOrder($order);
                $pushed = $result['pushed'];
                $invoice = $result['invoice'];
                $this->activityTracker->progress(1, 2, $pushed
                    ? 'Sipariş UyumSoft’a yazıldı.'
                    : 'Mevcut UyumSoft kaydı kontrol edildi.');
            } elseif ($order->uyumsoft_order_id !== null) {
                $this->activityTracker->log(
                    'info',
                    'UyumSoft sipariş kaydı mevcut ('.$order->uyumsoft_order_id.').'
                );

                if ($order->needsUyumsoftContentUpdate()) {
                    $this->activityTracker->log('info', 'Sipariş içeriği UyumSoft’ta güncelleniyor…');
                    $result = $this->pushOrder($order->fresh(['items']) ?? $order);
                    $pushed = $result['pushed'];
                    $invoice = $result['invoice'];
                } elseif (! $order->hasInvoice()) {
                    $this->activityTracker->log('info', 'Fatura sorgulanıyor…');
                    $invoice = $this->pullInvoice($order);
                } else {
                    $this->activityTracker->log('info', 'Fatura zaten panelde mevcut.');
                }

                $this->activityTracker->progress(1, 2);
            } elseif (! $this->shouldPush($order)) {
                throw new UyumSoftException('Bu sipariş UyumSoft’a gönderilemez (iptal, iade veya kalem yok).');
            } else {
                $this->activityTracker->log('info', 'UyumSoft’a gönderilecek yeni satış bulunamadı.');
            }

            if (! $invoice && $order->fresh()?->hasInvoice() === false && $order->uyumsoft_order_id !== null) {
                $this->activityTracker->log('info', 'Siparişe bağlı PDF/XML belge alınamadı.');
            }

            $freshOrder = $order->fresh(['items']) ?? $order;
            $message = $this->buildSingleOrderMessage($freshOrder, $pushed, $invoice);
            $synced = ($pushed ? 1 : 0) + ($invoice ? 1 : 0);
            $documentError = filled($freshOrder->uyumsoft_invoice_id) && ! $freshOrder->hasInvoice();

            $this->activityTracker->complete($message, $synced, $documentError ? 1 : 0, [
                'pushed' => $pushed,
                'invoice' => $invoice,
                'order_id' => $order->id,
                'manual' => true,
                'invoice_matched' => filled($freshOrder->uyumsoft_invoice_id),
                'document_downloaded' => $freshOrder->hasInvoice(),
            ]);

            return [
                'pushed' => $pushed,
                'invoice' => $invoice,
                'message' => $message,
            ];
        } catch (Throwable $e) {
            $this->markOrderError($order, $e->getMessage());
            $this->activityTracker->fail($order->order_number.': '.$e->getMessage(), $e);

            throw $e;
        }
    }

    private function buildSingleOrderMessage(ShopifyOrder $order, bool $pushed, bool $invoice): string
    {
        $parts = [];

        if ($pushed) {
            $parts[] = 'UyumSoft siparişi oluşturuldu';
        }
        if ($invoice) {
            $parts[] = 'fatura çekildi';
        }

        if ($parts === []) {
            if ($order->uyumsoft_order_id) {
                if ($order->hasInvoice()) {
                    $parts[] = 'UyumSoft kaydı ve fatura zaten mevcut';
                } elseif ($order->uyumsoft_invoice_id) {
                    $parts[] = 'UyumSoft faturası eşleşti'
                        .($order->uyumsoft_invoice_no ? ' ('.$order->uyumsoft_invoice_no.')' : '')
                        .', ancak PDF/XML içeriği alınamadı';
                } else {
                    $parts[] = 'UyumSoft kaydı var, siparişe bağlı/eşleşen fatura bulunamadı';
                }
            } else {
                $parts[] = 'UyumSoft’a gönderilecek yeni satış yok';
            }
        } elseif (! $invoice && $order->uyumsoft_order_id && ! $order->hasInvoice()) {
            $parts[] = 'fatura henüz hazır değil';
        }

        return implode(', ', $parts).'.';
    }

    /**
     * @return array{pushed: bool, invoice: bool}
     */
    private function pushOrder(ShopifyOrder $order): array
    {
        if (! $this->shouldPush($order)) {
            return ['pushed' => false, 'invoice' => false];
        }

        if ($this->shouldLockInvoicedUpdate($order)) {
            $this->markInvoiceLockedWarning($order);
            $this->activityTracker->log(
                'warning',
                $order->order_number.': fatura kesildiği için UyumSoft siparişi güncellenmedi.'
            );

            return ['pushed' => false, 'invoice' => $this->pullInvoice($order)];
        }

        if ($order->uyumsoft_order_id) {
            if (! $order->needsUyumsoftContentUpdate()) {
                return ['pushed' => false, 'invoice' => $this->pullInvoice($order)];
            }

            return $this->updateExistingOrder($order);
        }

        $existing = $this->uyumSoftService->findSalesOrder($this->erpDocNo($order), $order->order_number);
        if ($existing !== null) {
            $hash = $order->fresh(['items'])?->contentHash() ?? $order->contentHash();
            $order->update([
                'uyumsoft_order_id' => $this->extractRecordId($existing),
                'uyumsoft_pushed_at' => now(),
                'uyumsoft_last_error' => null,
                'uyumsoft_content_hash' => $hash,
                'shopify_content_hash' => $hash,
                'uyumsoft_needs_update' => false,
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
            'uyumsoft_content_hash' => $order->fresh(['items'])?->contentHash() ?? $order->contentHash(),
            'uyumsoft_needs_update' => false,
            'shopify_content_hash' => $order->fresh(['items'])?->contentHash() ?? $order->contentHash(),
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
            $invoice = $this->uyumSoftService->getInvoiceDetails((string) $order->uyumsoft_invoice_id);
            $invoice = array_merge([
                'id' => $order->uyumsoft_invoice_id,
                'invoiceId' => $order->uyumsoft_invoice_id,
                'docNo' => $order->uyumsoft_invoice_no,
            ], $invoice);
        } else {
            $invoice = $this->uyumSoftService->findInvoiceForOrder(
                $this->erpDocNo($order),
                $order->order_number,
                (string) $order->uyumsoft_order_id,
                [
                    'customer_name' => $order->customer_name,
                    'total' => $order->total_price,
                    'currency' => $order->currency,
                ]
            );
        }

        if ($invoice === null) {
            $this->activityTracker->log('info', $order->order_number.' için sipariş numarası ile doğrulanmış fatura bulunamadı.', [
                'uyumsoft_order_id' => $order->uyumsoft_order_id,
                'erp_doc_no' => $this->erpDocNo($order),
            ]);

            return false;
        }

        $invoiceId = $this->extractRecordId($invoice) ?: $order->uyumsoft_invoice_id;
        $invoiceNo = (string) ($invoice['invoiceNo']
            ?? $invoice['eDocNo']
            ?? $invoice['eArchiveNo']
            ?? $invoice['eInvoiceDocNo']
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

        $document = $invoiceId ? $this->uyumSoftService->getInvoiceDocument($invoiceId) : null;
        if ($document === null) {
            $invoiceUuid = $this->resolveOfficialInvoiceUuid($invoice, $invoiceNo, $order);
            if ($invoiceUuid !== '') {
                $this->attachPortalInvoice(
                    $order,
                    $invoiceNo !== '' ? $invoiceNo : (string) $order->order_number,
                    $invoiceUuid
                );
                $this->activityTracker->log(
                    'info',
                    $order->order_number.' faturası portal belgesi olarak bağlandı; dosya kaydedilmedi.',
                    [
                        'invoice_id' => $invoiceId,
                        'invoice_no' => $invoiceNo,
                        'invoice_uuid' => $invoiceUuid,
                    ]
                );

                return true;
            }
        }

        if ($document !== null) {
            $this->attachInvoiceDocument(
                $order,
                $document['content'],
                $invoiceNo !== '' ? $invoiceNo : $order->order_number,
                $document['extension'],
                $document['uuid'] ?? null
            );
            $this->activityTracker->log(
                'info',
                sprintf(
                    '%s faturası UyumSoft’tan %s olarak alındı (%s bayt, kaynak: %s).',
                    $order->order_number,
                    strtoupper($document['extension']),
                    number_format(strlen($document['content']), 0, ',', '.'),
                    $document['source']
                ),
                [
                    'invoice_id' => $invoiceId,
                    'invoice_no' => $invoiceNo,
                    'format' => $document['extension'],
                    'bytes' => strlen($document['content']),
                    'source' => $document['source'],
                ]
            );

            return true;
        }

        $this->activityTracker->log('warning', $order->order_number.' için fatura kaydı bulundu fakat PDF/XML içeriği alınamadı.', [
            'invoice_id' => $invoiceId,
            'invoice_no' => $invoiceNo,
            'available_fields' => array_keys($invoice),
        ]);

        return false;
    }

    /**
     * @param  array<string, mixed>  $invoice
     */
    private function resolveOfficialInvoiceUuid(array $invoice, string $invoiceNo, ShopifyOrder $order): string
    {
        $uuid = trim((string) collect([
            $invoice['eGuid'] ?? null,
            $invoice['guID'] ?? null,
            $invoice['uuid'] ?? null,
            $invoice['guid'] ?? null,
        ])->first(static fn ($value): bool => filled($value)));

        if ($uuid !== '') {
            return $uuid;
        }

        if (! $this->eInvoiceService->hasDedicatedCredentials()) {
            return '';
        }

        $documentNo = trim((string) collect([
            $invoiceNo,
            $invoice['eDocNo'] ?? null,
            $invoice['docNo'] ?? null,
            $order->uyumsoft_invoice_no,
        ])->first(static fn ($value): bool => filled($value) && ! str_contains((string) $value, ' ')));

        if ($documentNo === '') {
            return '';
        }

        try {
            $this->activityTracker->log('info', 'e-Fatura giden kutusunda belge numarası aranıyor.', [
                'invoice_no' => $documentNo,
            ]);
            $found = $this->eInvoiceService->findOutboxInvoice($documentNo);
        } catch (Throwable $e) {
            $this->activityTracker->log('warning', 'Giden kutu sorgusu başarısız: '.$e->getMessage(), [
                'invoice_no' => $documentNo,
            ]);
            Log::channel('stack')->warning('UyumSoft official invoice lookup failed', [
                'invoice_no' => $documentNo,
                'message' => $e->getMessage(),
            ]);

            return '';
        }

        if ($found === null) {
            return '';
        }

        return trim($found['document_id'] !== '' ? $found['document_id'] : $found['invoice_id']);
    }

    private function attachPortalInvoice(ShopifyOrder $order, string $invoiceNo, string $uuid): void
    {
        if ($order->invoice_path && Storage::disk('public')->exists($order->invoice_path)) {
            Storage::disk('public')->delete($order->invoice_path);
        }

        $order->update([
            'invoice_path' => null,
            'invoice_original_name' => 'UyumSoft-'.$invoiceNo.'.pdf',
            'invoice_uploaded_at' => now(),
            'invoice_token' => $order->newInvoiceToken(),
            'uyumsoft_einvoice_uuid' => $uuid,
            'shopify_needs_push' => true,
        ]);

        $this->publishInvoiceLink($order);
    }

    private function attachInvoiceDocument(
        ShopifyOrder $order,
        string $binary,
        string $invoiceNo,
        string $extension,
        ?string $einvoiceUuid = null
    ): void {
        $extension = strtolower($extension) === 'xml' ? 'xml' : 'pdf';

        if ($order->invoice_path && Storage::disk('public')->exists($order->invoice_path)) {
            Storage::disk('public')->delete($order->invoice_path);
        }

        $filename = Str::uuid()->toString().'.'.$extension;
        $path = 'order-invoices/'.$order->id.'/'.$filename;
        Storage::disk('public')->put($path, $binary);

        $order->update([
            'invoice_path' => $path,
            'invoice_original_name' => 'UyumSoft-'.$invoiceNo.'.'.$extension,
            'invoice_uploaded_at' => now(),
            'invoice_token' => $order->newInvoiceToken(),
            'uyumsoft_einvoice_uuid' => filled($einvoiceUuid) ? $einvoiceUuid : $order->uyumsoft_einvoice_uuid,
            'shopify_needs_push' => true,
        ]);

        $this->publishInvoiceLink($order);
    }

    private function publishInvoiceLink(ShopifyOrder $order): void
    {
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
            ...(filled($order->uyumsoft_order_id) ? [
                'id' => $order->uyumsoft_order_id,
                'Id' => $order->uyumsoft_order_id,
            ] : []),
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
        foreach ([
            'id',
            'orderId',
            'orderMId',
            'invoiceId',
            'invoiceMId',
            'eInvoiceId',
            'eArchiveId',
            'documentId',
            'docId',
            'uuid',
            'ettn',
            'docNo',
        ] as $key) {
            $value = $record[$key] ?? null;
            if (filled($value)) {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * @return array{pushed: bool, invoice: bool}
     */
    private function updateExistingOrder(ShopifyOrder $order): array
    {
        $payload = $this->buildOrderPayload($order);
        $response = $this->uyumSoftService->updateSalesOrder($payload);
        $record = is_array($response['result'] ?? null) ? $response['result'] : $response;
        $remoteId = $this->extractRecordId($record) ?? (string) $order->uyumsoft_order_id;
        $hash = $order->fresh(['items'])?->contentHash() ?? $order->contentHash();

        $order->update([
            'uyumsoft_order_id' => $remoteId,
            'uyumsoft_pushed_at' => now(),
            'uyumsoft_last_error' => null,
            'uyumsoft_content_hash' => $hash,
            'shopify_content_hash' => $hash,
            'uyumsoft_needs_update' => false,
        ]);

        $this->activityTracker->log('info', $order->order_number.' UyumSoft siparişi güncellendi ('.$remoteId.').');

        return ['pushed' => true, 'invoice' => $this->pullInvoice($order->fresh() ?? $order)];
    }

    private function shouldLockInvoicedUpdate(ShopifyOrder $order): bool
    {
        if (! filled($order->uyumsoft_order_id) || ! $order->uyumsoftInvoiceLocked()) {
            return false;
        }

        return $order->uyumsoft_needs_update
            || $order->uyumsoft_invoice_locked
            || (filled($order->shopify_content_hash)
                && filled($order->uyumsoft_content_hash)
                && $order->shopify_content_hash !== $order->uyumsoft_content_hash);
    }

    private function markInvoiceLockedWarning(ShopifyOrder $order): void
    {
        $order->update([
            'uyumsoft_invoice_locked' => true,
            'uyumsoft_needs_update' => false,
        ]);
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
