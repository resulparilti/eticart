<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\ProductSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncActiveShopifyProducts implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 600;

    /**
     * Periodic equality for active, already-synced products.
     */
    public function handle(ProductSyncService $productSyncService): void
    {
        $result = $productSyncService->syncActiveToShopify([
            ProductSyncService::OPTION_STOCK,
            ProductSyncService::OPTION_PRICE,
            ProductSyncService::OPTION_INFO,
        ]);

        Log::channel('stack')->info('SyncActiveShopifyProducts completed', $result);
    }

    public function failed(?Throwable $exception): void
    {
        Log::channel('stack')->error('SyncActiveShopifyProducts failed', [
            'message' => $exception?->getMessage(),
        ]);
    }
}
