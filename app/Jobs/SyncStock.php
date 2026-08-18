<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\ProductSyncService;
use App\Services\SyncActivityTracker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncStock implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    /**
     * Execute the job.
     */
    public function handle(ProductSyncService $productSyncService, SyncActivityTracker $tracker): void
    {
        $tracker->ensureFresh('stock_sync', 'Zamanlanmış stok tarama');
        $tracker->markRunning('UyumSoft stokları kontrol ediliyor…');

        try {
            $result = $productSyncService->syncStock();
            Log::channel('stack')->info('SyncStock completed', $result);
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
        Log::channel('stack')->error('SyncStock failed', [
            'message' => $exception?->getMessage(),
        ]);
    }
}
