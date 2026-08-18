<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\SyncActivity;
use App\Services\ProductSyncService;
use App\Services\SyncActivityTracker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncUyumSoftProducts implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 1200;

    public function __construct(
        public int $limit = 50,
        public int $offset = 0,
        public bool $fullCatalog = true,
        public bool $pushToShopify = true,
        public ?int $activityId = null
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(ProductSyncService $productSyncService, SyncActivityTracker $tracker): void
    {
        if ($this->activityId) {
            $activity = SyncActivity::query()->find($this->activityId);
            if ($activity) {
                $tracker->bind($activity);
            }
        } else {
            $tracker->reset();
            $activity = $tracker->start(
                $this->pushToShopify ? 'product_reconcile' : 'product_sync',
                $this->pushToShopify ? 'Zamanlanmış UyumSoft → Shopify eşitleme' : 'Zamanlanmış UyumSoft ürün çekimi'
            );
            $this->activityId = $activity->id;
        }

        try {
            if ($this->fullCatalog) {
                $result = $productSyncService->syncAllFromUyumSoftAndReconcile(
                    $this->limit,
                    $this->pushToShopify,
                    [
                        ProductSyncService::OPTION_STOCK,
                        ProductSyncService::OPTION_PRICE,
                        ProductSyncService::OPTION_INFO,
                    ]
                );
            } else {
                $result = $productSyncService->syncFromUyumSoft($this->limit, $this->offset);
            }

            Log::channel('stack')->info('SyncUyumSoftProducts completed', $result);
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
        Log::channel('stack')->error('SyncUyumSoftProducts failed', [
            'message' => $exception?->getMessage(),
        ]);

        if ($this->activityId) {
            $activity = SyncActivity::query()->find($this->activityId);
            if ($activity && $activity->isActive()) {
                app(SyncActivityTracker::class)->bind($activity);
                app(SyncActivityTracker::class)->fail(
                    $exception?->getMessage() ?? 'Kuyruk işi başarısız.',
                    $exception
                );
            }
        }
    }
}
