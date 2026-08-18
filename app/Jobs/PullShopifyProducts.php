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

class PullShopifyProducts implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 900;

    /**
     * Execute the job.
     */
    public function handle(ProductSyncService $productSyncService): void
    {
        $result = $productSyncService->pullFromShopify();

        Log::channel('stack')->info('PullShopifyProducts completed', $result);
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Throwable $exception): void
    {
        Log::channel('stack')->error('PullShopifyProducts failed', [
            'message' => $exception?->getMessage(),
        ]);
    }
}
