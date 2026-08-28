<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\OrderSyncService;
use App\Services\SyncActivityTracker;
use App\Services\UyumSoftOrderSyncService;
use App\Services\UyumSoftService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncShopifyOrders implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 900;

    public int $uniqueFor = 900;

    public function __construct(
        public int $limit = 50,
        public string $status = 'any'
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(
        OrderSyncService $orderSyncService,
        UyumSoftOrderSyncService $uyumSoftOrderSyncService,
        UyumSoftService $uyumSoftService,
        SyncActivityTracker $tracker
    ): void {
        $tracker->ensureFresh('order_sync', 'Shopify sipariş tarama');
        $tracker->markRunning('Shopify siparişleri kontrol ediliyor…');

        try {
            $result = $orderSyncService->sync($this->limit, $this->status);
            Log::channel('stack')->info('SyncShopifyOrders completed', $result);
        } catch (Throwable $e) {
            $tracker->fail($e->getMessage(), $e);
            throw $e;
        }

        if (! $uyumSoftService->isConfigured()) {
            return;
        }

        try {
            $tracker->ensureFresh('uyumsoft_order_sync', 'UyumSoft sipariş eşitleme');
            $tracker->markRunning('UyumSoft siparişleri eşitleniyor…');
            $uyum = $uyumSoftOrderSyncService->sync($this->limit);
            Log::channel('stack')->info('SyncShopifyOrders uyumsoft completed', $uyum);
        } catch (Throwable $e) {
            Log::channel('stack')->error('SyncShopifyOrders uyumsoft failed', [
                'message' => $e->getMessage(),
            ]);
            if ($tracker->current()) {
                $tracker->fail($e->getMessage(), $e);
            }
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Throwable $exception): void
    {
        Log::channel('stack')->error('SyncShopifyOrders failed', [
            'message' => $exception?->getMessage(),
        ]);
    }
}
