<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\CargoException;
use App\Models\CargoCompany;
use App\Models\Setting;
use App\Models\Shipment;
use App\Models\ShipmentTrackingEvent;
use App\Models\ShopifyOrder;
use App\Models\SyncJob;
use App\Models\SyncJobLog;
use App\Services\Cargo\ArasCargoService;
use App\Services\Cargo\CargoServiceInterface;
use App\Services\Cargo\MngCargoService;
use App\Services\Cargo\PttCargoService;
use App\Services\Cargo\YurticiCargoService;
use App\Support\YurticiStatusLabels;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class CargoService
{
    /**
     * Resolve provider implementation for a cargo company.
     */
    public function resolveProvider(CargoCompany $company): CargoServiceInterface
    {
        return match ($company->provider_type) {
            'aras' => new ArasCargoService($company),
            'mng' => new MngCargoService($company),
            'yurtici' => new YurticiCargoService($company),
            'ptt' => new PttCargoService($company),
            default => throw new CargoException("Desteklenmeyen kargo sağlayıcısı: {$company->provider_type}"),
        };
    }

    /**
     * Create shipment for an order.
     *
     * @param  array<string, mixed>  $payload
     */
    public function createShipment(int $orderId, int $cargoCompanyId, array $payload = []): Shipment
    {
        $order = ShopifyOrder::query()->findOrFail($orderId);
        $company = CargoCompany::query()->findOrFail($cargoCompanyId);
        $provider = $this->resolveProvider($company);

        if (($payload['allow_local_fallback'] ?? true) === false && ! $provider->isConfigured()) {
            throw new CargoException('Kargo firması API bilgileri eksik. Ayarlar → Kargo ekranından tanımlayın.');
        }

        $data = array_merge([
            'order_number' => $order->order_number,
            'receiver_name' => $order->customer_name,
            'receiver_phone' => $order->customer_phone,
            'receiver_address' => $order->shipping_address,
            'receiver_city' => $order->shipping_city,
            'amount' => $order->total_price,
            'weight' => 1,
        ], $payload);

        $locality = $order->resolveShippingLocality();
        if ($company->provider_type === 'yurtici') {
            $data['receiver_city'] = $locality['city'];
            $data['receiver_town'] = $locality['town'];
            if (($locality['street'] ?? '') !== '') {
                $data['receiver_address'] = $locality['street'];
            }
        } else {
            if (! filled($data['receiver_city'] ?? null)) {
                $data['receiver_city'] = $locality['city'] !== '' ? $locality['city'] : $locality['town'];
            }
        }

        $result = $provider->createShipment($data);

        $queryInfo = is_array($result['query'] ?? null) ? $result['query'] : [];
        $acceptedNow = $this->isAcceptedAtBranch($queryInfo)
            || $this->hasPublicTracking($queryInfo, (string) ($result['cargo_key'] ?? $result['tracking_number'] ?? ''));

        $shipment = DB::transaction(function () use ($order, $company, $data, $result, $provider, $acceptedNow) {
            $modeTag = ! empty($result['recovered'])
                ? '[api-mevcut]'
                : ((($result['mode'] ?? '') === 'api') ? '[api]' : '[local]');
            $notes = trim($modeTag.' '.((string) ($data['notes'] ?? '')));

            $shipment = Shipment::query()->create([
                'shopify_order_id' => $order->id,
                'cargo_company_id' => $company->id,
                'order_number' => $order->order_number,
                'tracking_number' => $result['tracking_number'] ?? null,
                'cargo_key' => $result['cargo_key'] ?? $result['tracking_number'] ?? null,
                'cargo_job_id' => isset($result['job_id']) && $result['job_id'] !== null && $result['job_id'] !== ''
                    ? (string) $result['job_id']
                    : null,
                'tracking_url' => $result['tracking_url'] ?? null,
                'status' => $acceptedNow ? Shipment::STATUS_SHIPPED : Shipment::STATUS_PENDING,
                'receiver_name' => $data['receiver_name'] ?? null,
                'receiver_phone' => $data['receiver_phone'] ?? null,
                'receiver_address' => $data['receiver_address'] ?? null,
                'receiver_city' => $data['receiver_city'] ?? null,
                'weight' => $data['weight'] ?? null,
                'cargo_cost' => $data['cargo_cost'] ?? null,
                'insurance' => $data['insurance'] ?? null,
                'amount' => $data['amount'] ?? $order->total_price,
                'notes' => $notes !== '' ? $notes : null,
                'provider_payload' => [
                    'create' => [
                        'mode' => $result['mode'] ?? null,
                        'out_flag' => $result['out_flag'] ?? null,
                        'job_id' => $result['job_id'] ?? null,
                        'cargo_key' => $result['cargo_key'] ?? null,
                        'message' => $result['message'] ?? null,
                        'at' => now()->toIso8601String(),
                    ],
                ],
                'shipped_at' => $acceptedNow ? now() : null,
            ]);

            $labelPath = $provider->generateLabel($shipment->id);
            $invoicePath = $provider->generateInvoice($shipment->id);

            $shipment->update([
                'label_path' => $labelPath,
                'invoice_path' => $invoicePath,
            ]);

            Log::channel('stack')->info('Shipment created', [
                'shipment_id' => $shipment->id,
                'provider' => $company->provider_type,
                'mode' => $result['mode'] ?? 'unknown',
                'tracking_number' => $shipment->tracking_number,
            ]);

            return $shipment->fresh(['cargoCompany', 'order']);
        });

        $lifecycle = app(OrderLifecycleService::class);
        $lifecycle->markPreparing($order->fresh(), $shipment);

        if ($acceptedNow) {
            $lifecycle->markShipped($order->fresh(), $shipment->fresh(['cargoCompany']), $result);
        }

        return $shipment->fresh(['cargoCompany', 'order']);
    }

    /**
     * Generate/refresh label path.
     */
    public function generateLabel(int $shipmentId): string
    {
        $shipment = Shipment::query()->with('cargoCompany')->findOrFail($shipmentId);

        if (! $shipment->cargoCompany) {
            throw new CargoException('Kargo firması seçilmemiş.');
        }

        $path = $this->resolveProvider($shipment->cargoCompany)->generateLabel($shipment->id);
        $shipment->update(['label_path' => $path]);

        return $path;
    }

    /**
     * Generate/refresh invoice path.
     */
    public function generateInvoice(int $shipmentId): string
    {
        $shipment = Shipment::query()->with('cargoCompany')->findOrFail($shipmentId);

        if (! $shipment->cargoCompany) {
            throw new CargoException('Kargo firması seçilmemiş.');
        }

        $path = $this->resolveProvider($shipment->cargoCompany)->generateInvoice($shipment->id);
        $shipment->update(['invoice_path' => $path]);

        return $path;
    }

    /**
     * Live queryShipment (cargoKey, keyType=0) for Yurtiçi verify / status.
     *
     * @return array<string, mixed>
     */
    public function queryYurtici(Shipment $shipment): array
    {
        $shipment->loadMissing('cargoCompany');
        $company = $shipment->cargoCompany;

        if (! $company || $company->provider_type !== 'yurtici') {
            throw new CargoException('Bu gönderi Yurtiçi Kargo değil.');
        }

        $key = $shipment->cargoKey();
        if ($key === '') {
            throw new CargoException('Sorgulamak için cargoKey yok.');
        }

        $provider = $this->resolveProvider($company);
        if (! method_exists($provider, 'queryShipment')) {
            throw new CargoException('Yurtiçi sorgu desteklenmiyor.');
        }

        /** @var YurticiCargoService $provider */
        $paymentType = (string) data_get($company->settings, 'default_payment_type', 'sender');
        $result = method_exists($provider, 'queryShipmentByKey')
            ? $provider->queryShipmentByKey($key, 0, $paymentType)
            : $provider->queryShipment($key, 0, $paymentType);

        $operationStatus = $result['operation_status'] ?? null;
        $result['operation_status_label'] = YurticiStatusLabels::operation(
            is_string($operationStatus) ? $operationStatus : null,
            is_string($result['operation_message'] ?? null) ? $result['operation_message'] : null
        );

        $payload = is_array($shipment->provider_payload) ? $shipment->provider_payload : [];
        $payload['last_query'] = [
            'registered' => (bool) ($result['registered'] ?? $result['success'] ?? false),
            'out_flag' => $result['out_flag'] ?? null,
            'job_id' => $result['job_id'] ?? null,
            'cargo_key' => $result['cargo_key'] ?? $key,
            'operation_status' => $operationStatus,
            'operation_status_label' => $result['operation_status_label'],
            'operation_message' => $result['operation_message'] ?? null,
            'doc_id' => $result['doc_id'] ?? null,
            'tracking_ready' => (bool) ($result['tracking_ready'] ?? false),
            'tracking_number' => $result['tracking_number'] ?? null,
            'message' => $result['message'] ?? null,
            'at' => now()->toIso8601String(),
        ];

        $updates = ['provider_payload' => $payload];
        if (filled($result['cargo_key'] ?? null) && blank($shipment->cargo_key)) {
            $updates['cargo_key'] = (string) $result['cargo_key'];
        }
        if (filled($result['job_id'] ?? null) && blank($shipment->cargo_job_id)) {
            $updates['cargo_job_id'] = (string) $result['job_id'];
        }
        if ($this->hasPublicTracking($result, $key) && filled($result['tracking_number'] ?? null)) {
            $updates['tracking_number'] = (string) $result['tracking_number'];
            if (filled($result['tracking_url'] ?? null)) {
                $updates['tracking_url'] = (string) $result['tracking_url'];
            }
        }
        $shipment->update($updates);

        $eventsAdded = $this->persistTrackingEvents(
            $shipment,
            is_array($result['events'] ?? null) ? $result['events'] : []
        );
        $result['events_added'] = $eventsAdded;

        try {
            $this->applyTrackingInfo($shipment->fresh(['cargoCompany', 'order']) ?? $shipment, $result);
        } catch (Throwable $e) {
            Log::channel('stack')->warning('Yurtiçi sorgu sonrası durum güncellenemedi', [
                'shipment_id' => $shipment->id,
                'message' => $e->getMessage(),
            ]);
        }

        return $result;
    }

    /**
     * Cancel a shipment via the cargo company API when possible.
     */
    public function cancelShipment(Shipment $shipment): Shipment
    {
        $shipment->loadMissing(['cargoCompany', 'order']);

        if ($shipment->status === Shipment::STATUS_CANCELLED) {
            throw new CargoException('Bu kargo zaten iptal edilmiş.');
        }

        if (in_array($shipment->status, [Shipment::STATUS_DELIVERED, Shipment::STATUS_RETURNED], true)) {
            throw new CargoException('Teslim edilmiş veya iade edilmiş kargo iptal edilemez.');
        }

        $company = $shipment->cargoCompany;
        $tracking = trim((string) $shipment->tracking_number);

        if ($company && $tracking !== '') {
            $provider = $this->resolveProvider($company);

            if ($provider->isConfigured()) {
                try {
                    $info = $provider->getTrackingInfo($tracking);
                } catch (Throwable $e) {
                    $info = [];
                    Log::channel('stack')->warning('Cargo cancel tracking lookup failed', [
                        'shipment_id' => $shipment->id,
                        'message' => $e->getMessage(),
                    ]);
                }

                if ($this->isAcceptedAtBranch($info)) {
                    throw new CargoException('Kargo şubeye teslim edilmiş. İptal yapılamaz.');
                }

                $cancelled = $provider->cancelShipment($tracking);
                if (! $cancelled) {
                    throw new CargoException('Kargo firması iptal isteğini reddetti. Gönderi şubeye ulaşmış olabilir.');
                }
            } elseif (str_contains((string) $shipment->notes, '[api]')) {
                throw new CargoException('Kargo firması API bilgileri eksik. İptal API üzerinden yapılamadı.');
            }
        }

        $note = trim((string) $shipment->notes);
        $cancelNote = '[iptal] '.now()->format('d.m.Y H:i');

        $shipment->update([
            'status' => Shipment::STATUS_CANCELLED,
            'notes' => $note === '' ? $cancelNote : $note."\n".$cancelNote,
        ]);

        Log::channel('stack')->info('Shipment cancelled', [
            'shipment_id' => $shipment->id,
            'tracking_number' => $tracking,
            'provider' => $company?->provider_type,
        ]);

        $shipment->delete();

        return $shipment;
    }

    /**
     * @param  array<string, mixed>  $info
     */
    public function isAcceptedAtBranch(array $info): bool
    {
        if (filled($info['doc_id'] ?? null)) {
            return true;
        }

        $status = strtolower((string) ($info['status'] ?? ''));
        if (in_array($status, ['delivered', 'returned'], true)) {
            return true;
        }

        $text = mb_strtolower((string) ($info['status_text'] ?? $info['message'] ?? ''), 'UTF-8');
        if ($text === '') {
            return false;
        }

        foreach ([
            'şube', 'sube',
            'kabul',
            'teslim alındı', 'teslim alindi',
            'işlem gördü', 'islem gordu',
            'dağıtım', 'dagitim',
            'yola çıktı', 'yola cikti',
            'teslim edildi',
            'alıcıya teslim', 'aliciya teslim',
        ] as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Update tracking statuses for open shipments.
     *
     * @return array{updated: int, checked: int, events_added: int, tracking_assigned: int, errors: int, message: string}
     */
    public function updateTrackingStatus(): array
    {
        $startedAt = microtime(true);
        $tracker = app(SyncActivityTracker::class);
        $syncJob = SyncJob::query()->firstOrCreate(
            ['job_type' => 'cargo_tracking'],
            [
                'status' => 'idle',
                'interval_minutes' => (int) Setting::getValue('sync_cargo_interval', 15),
                'is_active' => true,
            ]
        );

        $syncJob->update(['status' => 'running', 'last_error' => null]);

        $checked = 0;
        $updated = 0;
        $eventsAdded = 0;
        $trackingAssigned = 0;
        $errors = 0;
        $unchanged = 0;

        $shipments = Shipment::query()
            ->with(['cargoCompany', 'order'])
            ->whereIn('status', [Shipment::STATUS_PENDING, Shipment::STATUS_SHIPPED])
            ->where(function ($q) {
                $q->whereNotNull('tracking_number')
                    ->orWhereNotNull('cargo_key');
            })
            ->get();

        $tracker->setTotal($shipments->count());
        $tracker->log('info', $shipments->count().' açık kargo API üzerinden sorgulanacak.');

        foreach ($shipments as $shipment) {
            $checked++;
            try {
                if (! $shipment->cargoCompany) {
                    $tracker->log('warning', '#'.$shipment->id.' kargo firması yok, atlandı.');
                    $unchanged++;
                    $tracker->progress($checked, $shipments->count());
                    continue;
                }

                $queryKey = $shipment->cargoKey();
                if ($queryKey === '') {
                    $unchanged++;
                    $tracker->progress($checked, $shipments->count());
                    continue;
                }

                $info = $this->resolveProvider($shipment->cargoCompany)
                    ->getTrackingInfo($queryKey);

                $beforeTracking = (string) ($shipment->tracking_number ?? '');
                $beforeStatus = (string) $shipment->status;
                $changed = false;

                if (! empty($info['job_id']) && blank($shipment->cargo_job_id)) {
                    $shipment->cargo_job_id = (string) $info['job_id'];
                    $changed = true;
                }

                $publicTracking = trim((string) ($info['tracking_number'] ?? ''));
                if ($this->hasPublicTracking($info, $queryKey) && $publicTracking !== '' && $publicTracking !== $beforeTracking) {
                    $shipment->tracking_number = $publicTracking;
                    if (! empty($info['tracking_url'])) {
                        $shipment->tracking_url = (string) $info['tracking_url'];
                    }
                    $trackingAssigned++;
                    $changed = true;
                    $tracker->log(
                        'success',
                        ($shipment->order_number ?: '#'.$shipment->id).' takip no alındı: '.$publicTracking
                    );
                }

                $newEvents = $this->persistTrackingEvents($shipment, is_array($info['events'] ?? null) ? $info['events'] : []);
                $eventsAdded += $newEvents;
                if ($newEvents > 0) {
                    $changed = true;
                    $tracker->log(
                        'info',
                        ($shipment->order_number ?: '#'.$shipment->id).": {$newEvents} yeni kargo hareketi kaydedildi."
                    );
                }

                $statusApplied = $this->applyTrackingInfo($shipment, $info);
                if ($statusApplied) {
                    $changed = true;
                    $shipment = $shipment->fresh(['cargoCompany', 'order']) ?? $shipment;
                    if ($shipment->status === Shipment::STATUS_DELIVERED) {
                        $tracker->log('success', ($shipment->order_number ?: '#'.$shipment->id).' teslim edildi olarak işaretlendi.');
                    } elseif ($shipment->status === Shipment::STATUS_SHIPPED && $beforeStatus !== Shipment::STATUS_SHIPPED) {
                        $tracker->log('success', ($shipment->order_number ?: '#'.$shipment->id).' kargoya verildi + Shopify güncellemesi.');
                    }
                }

                $payload = is_array($shipment->provider_payload) ? $shipment->provider_payload : [];
                $payload['last_query'] = array_merge(
                    array_diff_key($info, ['raw' => true, 'raw_xml' => true]),
                    ['at' => now()->toIso8601String()]
                );
                $shipment->provider_payload = $payload;
                $shipment->save();

                if ($changed) {
                    $updated++;
                } else {
                    $unchanged++;
                    $tracker->log(
                        'info',
                        ($shipment->order_number ?: '#'.$shipment->id).' kontrol edildi — değişiklik yok.'
                    );
                }
            } catch (Throwable $e) {
                $errors++;
                $tracker->log('error', '#'.$shipment->id.' takip hatası: '.$e->getMessage());
                Log::channel('stack')->error('Cargo tracking update failed', [
                    'shipment_id' => $shipment->id,
                    'message' => $e->getMessage(),
                ]);
            }

            $tracker->progress($checked, $shipments->count());
        }

        $duration = round(microtime(true) - $startedAt, 2);
        $message = "{$checked} kargo kontrol edildi"
            .($updated ? ", {$updated} güncellendi" : ', değişiklik yok')
            .($trackingAssigned ? ", {$trackingAssigned} takip no alındı" : '')
            .($eventsAdded ? ", {$eventsAdded} yeni hareket" : '')
            .($unchanged ? ", {$unchanged} aynı" : '')
            .($errors ? ", {$errors} hata" : '')
            .'.';

        $syncJob->update([
            'status' => 'idle',
            'last_run' => now(),
            'next_run' => now()->addMinutes((int) $syncJob->interval_minutes),
            'last_error' => $errors > 0 ? "{$errors} takip hatası" : null,
        ]);

        SyncJobLog::query()->create([
            'sync_job_id' => $syncJob->id,
            'status' => $errors > 0 ? 'partial' : 'success',
            'message' => $message,
            'synced_count' => $updated,
            'error_count' => $errors,
            'duration' => $duration,
        ]);

        return [
            'updated' => $updated,
            'checked' => $checked,
            'events_added' => $eventsAdded,
            'tracking_assigned' => $trackingAssigned,
            'errors' => $errors,
            'message' => $message,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $events
     */
    public function persistTrackingEvents(Shipment $shipment, array $events): int
    {
        $this->pruneHeaderTrackingSnapshots($shipment);

        $added = 0;

        foreach ($events as $event) {
            if (! is_array($event) || $this->isHeaderTrackingSnapshot($event)) {
                continue;
            }

            $fingerprint = (string) ($event['fingerprint'] ?? md5(json_encode($event) ?: uniqid('ev', true)));
            $occurredAt = $this->parseTrackingOccurredAt($event['occurred_at'] ?? null);

            $existing = ShipmentTrackingEvent::query()
                ->where('shipment_id', $shipment->id)
                ->where(function ($query) use ($fingerprint, $event, $occurredAt) {
                    $query->where('fingerprint', $fingerprint);
                    if ($occurredAt) {
                        $query->orWhere(function ($sameDay) use ($event, $occurredAt) {
                            $sameDay->whereDate('occurred_at', $occurredAt->toDateString())
                                ->where('event_code', $event['event_code'] ?? null)
                                ->where('description', $event['description'] ?? null);
                        });
                    }
                })
                ->first();

            if ($existing) {
                $existingTime = optional($existing->occurred_at)->format('H:i:s');
                if ($occurredAt && ($existing->occurred_at === null || $existingTime === '00:00:00')) {
                    $existing->update([
                        'occurred_at' => $occurredAt,
                        'fingerprint' => $fingerprint,
                    ]);
                }

                continue;
            }

            ShipmentTrackingEvent::query()->create([
                'shipment_id' => $shipment->id,
                'fingerprint' => $fingerprint,
                'event_code' => isset($event['event_code']) ? (string) $event['event_code'] : null,
                'status' => isset($event['status']) ? (string) $event['status'] : null,
                'title' => isset($event['title']) ? (string) $event['title'] : null,
                'description' => isset($event['description']) ? (string) $event['description'] : null,
                'location' => isset($event['location']) ? (string) $event['location'] : null,
                'occurred_at' => $occurredAt,
                'raw' => $event['raw'] ?? $event,
            ]);
            $added++;
        }

        return $added;
    }

    /**
     * Gönderi başlığı (cargoKey + DLV + evrak tarihi) hareket olarak saklanmışsa siler.
     */
    private function pruneHeaderTrackingSnapshots(Shipment $shipment): void
    {
        $rows = ShipmentTrackingEvent::query()
            ->where('shipment_id', $shipment->id)
            ->get();

        foreach ($rows as $row) {
            $raw = is_array($row->raw) ? $row->raw : [];
            if ($this->isHeaderTrackingSnapshot(['raw' => $raw])) {
                $row->delete();
                continue;
            }

            $hasMovementStamp = array_key_exists('eventName', $raw)
                || array_key_exists('EventName', $raw)
                || array_key_exists('eventDate', $raw)
                || array_key_exists('EventDate', $raw);
            $looksLikeHeader = array_key_exists('operationMessage', $raw)
                || array_key_exists('operationStatus', $raw)
                || array_key_exists('OperationStatus', $raw);

            if ($looksLikeHeader && ! $hasMovementStamp) {
                $row->delete();
            }
        }
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function isHeaderTrackingSnapshot(array $event): bool
    {
        $raw = $event['raw'] ?? [];
        if (! is_array($raw)) {
            return false;
        }

        return array_key_exists('cargoKey', $raw)
            || array_key_exists('invoiceKey', $raw)
            || array_key_exists('InvoiceKey', $raw)
            || array_key_exists('invDocCargoTrxList', $raw)
            || array_key_exists('shippingDeliveryItemDetailVO', $raw);
    }

    private function parseTrackingOccurredAt(mixed $raw): ?Carbon
    {
        if ($raw instanceof Carbon) {
            return $raw;
        }

        if ($raw instanceof \DateTimeInterface) {
            return Carbon::instance(\DateTimeImmutable::createFromInterface($raw));
        }

        if (is_int($raw) || (is_float($raw) && floor((float) $raw) == $raw)) {
            $raw = (string) (int) $raw;
        }

        if (! is_string($raw)) {
            return null;
        }

        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        try {
            if (preg_match('/^(\d{4})(\d{2})(\d{2})(?:[\sT]?(\d{2})(\d{2})(\d{2})?)?$/', $raw, $m) === 1) {
                return Carbon::create(
                    (int) $m[1],
                    (int) $m[2],
                    (int) $m[3],
                    (int) ($m[4] ?? 0),
                    (int) ($m[5] ?? 0),
                    (int) ($m[6] ?? 0)
                );
            }

            return Carbon::parse($raw);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * True when Yurtiçi (or other) returned a public tracking number, not only cargoKey.
     *
     * @param  array<string, mixed>  $info
     */
    public function hasPublicTracking(array $info, string $referenceKey): bool
    {
        $tracking = trim((string) ($info['tracking_number'] ?? ''));
        if ($tracking === '') {
            return false;
        }

        if (! empty($info['tracking_ready']) && $tracking !== $referenceKey && ! str_starts_with($tracking, 'YK')) {
            return true;
        }

        return ctype_digit($tracking) && strlen($tracking) >= 10;
    }

    /**
     * Yurtiçi / sağlayıcı sorgusuna göre kargo ve sipariş durumunu günceller.
     *
     * @param  array<string, mixed>  $info
     */
    public function applyTrackingInfo(Shipment $shipment, array $info): bool
    {
        if (array_key_exists('success', $info) && $info['success'] === false) {
            return false;
        }

        $shipment->loadMissing(['order', 'cargoCompany']);
        $order = $shipment->order;
        $status = $this->resolveShipmentStatus($info);
        $accepted = $this->isAcceptedAtBranch($info)
            || $this->hasPublicTracking($info, $shipment->cargoKey());
        $beforeStatus = (string) $shipment->status;
        $beforeOrderStatus = (string) ($order?->fulfillment_status ?? '');
        $changed = false;

        $lifecycle = app(OrderLifecycleService::class);

        if ($status === Shipment::STATUS_DELIVERED) {
            $deliveredAt = $this->resolveDeliveredAt($info) ?? $shipment->delivered_at ?? now();
            $shipment->delivered_at = $deliveredAt;
            $shipment->save();
            if ($order) {
                $lifecycle->markDelivered($order, $shipment->fresh(['cargoCompany', 'order']) ?? $shipment, $deliveredAt);
            } else {
                $shipment->update([
                    'status' => Shipment::STATUS_DELIVERED,
                    'delivered_at' => $deliveredAt,
                ]);
            }
            $changed = true;
        } elseif (($accepted || $status === Shipment::STATUS_SHIPPED)
            && $order
            && ! in_array($order->fulfillment_status, ['fulfilled', 'delivered', 'cancelled'], true)
        ) {
            $shipment->save();
            $lifecycle->markShipped($order, $shipment->fresh(['cargoCompany', 'order']) ?? $shipment, $info);
            $changed = true;
        } else {
            $nextStatus = $accepted && $status === Shipment::STATUS_PENDING
                ? Shipment::STATUS_SHIPPED
                : $status;
            if ($nextStatus !== $beforeStatus) {
                $changed = true;
            }
            $shipment->status = $nextStatus;
            if ($status === Shipment::STATUS_DELIVERED) {
                $shipment->delivered_at = $this->resolveDeliveredAt($info) ?? $shipment->delivered_at ?? now();
                $changed = true;
            }
            $shipment->save();
        }

        $shipment = $shipment->fresh(['order']) ?? $shipment;
        $orderStatus = (string) ($shipment->order?->fulfillment_status ?? '');

        return $changed
            || $beforeStatus !== (string) $shipment->status
            || $beforeOrderStatus !== $orderStatus;
    }

    /**
     * @param  array<string, mixed>  $info
     */
    public function resolveShipmentStatus(array $info): string
    {
        $operation = strtoupper(trim((string) ($info['operation_status'] ?? '')));
        if ($operation === 'DLV') {
            return Shipment::STATUS_DELIVERED;
        }
        if ($operation === 'RTN') {
            return Shipment::STATUS_RETURNED;
        }
        if (in_array($operation, ['CNL', 'IPT'], true)) {
            return Shipment::STATUS_CANCELLED;
        }

        $status = strtolower(trim((string) ($info['status'] ?? '')));
        if ($status === Shipment::STATUS_DELIVERED || str_contains($status, 'deliver')) {
            return Shipment::STATUS_DELIVERED;
        }

        if ($this->infoIndicatesDelivered($info)) {
            return Shipment::STATUS_DELIVERED;
        }

        return $this->mapProviderStatus($status !== '' ? $status : 'shipped');
    }

    /**
     * Teslim hareketinin kendi eventDate/eventTime değerini kullanır; evrak tarihi değil.
     *
     * @param  array<string, mixed>  $info
     */
    private function resolveDeliveredAt(array $info): ?Carbon
    {
        $latest = null;

        foreach (is_array($info['events'] ?? null) ? $info['events'] : [] as $event) {
            if (! is_array($event) || $this->isHeaderTrackingSnapshot($event)) {
                continue;
            }

            $haystack = mb_strtolower(trim(implode(' ', [
                (string) ($event['event_code'] ?? ''),
                (string) ($event['status'] ?? ''),
                (string) ($event['title'] ?? ''),
                (string) ($event['description'] ?? ''),
            ])), 'UTF-8');

            $code = strtoupper(trim((string) ($event['status'] ?? $event['event_code'] ?? '')));
            $isDelivered = $code === 'DLV'
                || str_contains($haystack, 'teslim edildi')
                || str_contains($haystack, 'alıcıya teslim')
                || str_contains($haystack, 'aliciya teslim');

            if (! $isDelivered) {
                continue;
            }

            $at = $this->parseTrackingOccurredAt($event['occurred_at'] ?? null);
            if ($at && ($latest === null || $at->gt($latest))) {
                $latest = $at;
            }
        }

        return $latest;
    }

    /**
     * @param  array<string, mixed>  $info
     */
    private function infoIndicatesDelivered(array $info): bool
    {
        $chunks = [
            (string) ($info['status_text'] ?? ''),
            (string) ($info['message'] ?? ''),
            (string) ($info['operation_message'] ?? ''),
            (string) ($info['operation_status'] ?? ''),
        ];

        foreach (is_array($info['events'] ?? null) ? $info['events'] : [] as $event) {
            if (! is_array($event)) {
                continue;
            }
            $chunks[] = (string) ($event['description'] ?? '');
            $chunks[] = (string) ($event['title'] ?? '');
            $chunks[] = (string) ($event['status'] ?? '');
            $chunks[] = (string) ($event['event_code'] ?? '');
        }

        $haystack = mb_strtolower(trim(implode(' ', $chunks)), 'UTF-8');
        if ($haystack === '') {
            return false;
        }

        foreach ([
            'teslim edildi',
            'alıcıya teslim',
            'aliciya teslim',
            'teslimat gerçekleş',
            'teslimat gercekles',
        ] as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Map provider status text to local status.
     */
    private function mapProviderStatus(string $status): string
    {
        $status = strtolower($status);

        return match (true) {
            str_contains($status, 'deliver') => Shipment::STATUS_DELIVERED,
            str_contains($status, 'return') => Shipment::STATUS_RETURNED,
            str_contains($status, 'cancel') => Shipment::STATUS_CANCELLED,
            str_contains($status, 'pending') => Shipment::STATUS_PENDING,
            default => Shipment::STATUS_SHIPPED,
        };
    }
}
