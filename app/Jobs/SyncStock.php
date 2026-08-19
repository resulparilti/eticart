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

class SyncStock implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    public function __construct(
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
            $tracker->ensureFresh('stock_sync', 'Zamanlanmış stok tarama');
        }

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
            'activity_id' => $this->activityId,
            'message' => $exception?->getMessage(),
        ]);

        if (! $this->activityId) {
            return;
        }

        $activity = SyncActivity::query()->find($this->activityId);
        if (! $activity || ! $activity->isActive()) {
            return;
        }

        $tracker = app(SyncActivityTracker::class);
        $tracker->bind($activity);
        $tracker->fail($exception?->getMessage() ?? 'Kuyruk işi başarısız.', $exception);
    }
}
