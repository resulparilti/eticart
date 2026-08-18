<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\SyncActivityTracker;
use App\Services\UyumSoftOrderSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncUyumSoftOrders implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    public function __construct(
        public int $limit = 50
    ) {
    }

    public function handle(UyumSoftOrderSyncService $syncService, SyncActivityTracker $tracker): void
    {
        $tracker->ensureFresh('uyumsoft_order_sync', 'Zamanlanmış UyumSoft sipariş / fatura senkronu');
        $tracker->markRunning('Shopify satışları UyumSoft’a yazılıyor…');

        try {
            $result = $syncService->sync($this->limit);
            Log::channel('stack')->info('SyncUyumSoftOrders completed', $result);
        } catch (Throwable $e) {
            $tracker->fail($e->getMessage(), $e);
            throw $e;
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::channel('stack')->error('SyncUyumSoftOrders failed', [
            'message' => $exception?->getMessage(),
        ]);
    }
}