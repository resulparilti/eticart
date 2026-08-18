<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\OrderSyncService;
use App\Services\SyncActivityTracker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncShopifyOrders implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    public function __construct(
        public int $limit = 50,
        public string $status = 'any'
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(OrderSyncService $orderSyncService, SyncActivityTracker $tracker): void
    {
        $tracker->ensureFresh('order_sync', 'Zamanlanmış Shopify sipariş tarama');
        $tracker->markRunning('Shopify siparişleri kontrol ediliyor…');

        try {
            $result = $orderSyncService->sync($this->limit, $this->status);
            Log::channel('stack')->info('SyncShopifyOrders completed', $result);
        } catch (Throwable $e) {
            $tracker->fail($e->getMessage(), $e);
            throw $e;
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
