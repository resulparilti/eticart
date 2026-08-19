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

class ProcessBulkProductAction implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 1200;

    /**
     * @param  array<int, int>  $productIds
     * @param  array<int, string>  $options
     */
    public function __construct(
        public string $action,
        public array $productIds,
        public array $options,
        public int $activityId,
        public ?int $userId = null,
    ) {
    }

    public function handle(ProductSyncService $sync, SyncActivityTracker $tracker): void
    {
        $activity = SyncActivity::query()->find($this->activityId);
        if (! $activity) {
            return;
        }

        $tracker->bind($activity);

        try {
            match ($this->action) {
                'reconcile' => $this->runReconcile($sync, $tracker),
                'pull_shopify' => $this->runPull($sync, $tracker),
                'push_shopify' => $this->runPush($sync, $tracker),
                'activate', 'deactivate' => $this->runStatus($sync, $tracker),
                default => $tracker->fail('Bilinmeyen toplu işlem: '.$this->action),
            };
        } catch (Throwable $e) {
            report($e);
            $tracker->fail($e->getMessage(), $e);
            throw $e;
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::channel('stack')->error('ProcessBulkProductAction failed', [
            'action' => $this->action,
            'activity_id' => $this->activityId,
            'message' => $exception?->getMessage(),
        ]);

        $activity = SyncActivity::query()->find($this->activityId);
        if (! $activity || ! $activity->isActive()) {
            return;
        }

        $tracker = app(SyncActivityTracker::class);
        $tracker->bind($activity);
        $tracker->fail($exception?->getMessage() ?? 'Kuyruk işi başarısız.', $exception);
    }

    private function runReconcile(ProductSyncService $sync, SyncActivityTracker $tracker): void
    {
        $tracker->markRunning('UyumSoft kataloğu çekilip Shopify ile kontrol ediliyor…');
        $sync->syncAllFromUyumSoftAndReconcile(50, true);
    }

    private function runPull(ProductSyncService $sync, SyncActivityTracker $tracker): void
    {
        $tracker->markRunning('Shopify ürün güncellemeleri çekiliyor…');
        $result = $sync->pullShopifyUpdates($this->productIds);
        $tracker->complete(
            $result['message'],
            (int) ($result['synced'] ?? 0),
            (int) ($result['errors'] ?? 0),
            ['skipped' => $result['skipped'] ?? 0, 'user_id' => $this->userId]
        );
    }

    private function runPush(ProductSyncService $sync, SyncActivityTracker $tracker): void
    {
        $tracker->markRunning('Shopify aktarımı başladı…');
        $result = $sync->syncToShopify($this->productIds, $this->options);
        $tracker->complete(
            $result['message'],
            (int) ($result['synced'] ?? 0),
            (int) ($result['errors'] ?? 0),
            ['skipped' => $result['skipped'] ?? 0, 'user_id' => $this->userId]
        );
    }

    private function runStatus(ProductSyncService $sync, SyncActivityTracker $tracker): void
    {
        $tracker->markRunning('Shopify ürün durumları güncelleniyor…');
        $result = $sync->syncActiveStatusToShopify($this->productIds);
        $tracker->complete(
            $result['message'],
            (int) ($result['synced'] ?? 0),
            (int) ($result['errors'] ?? 0),
            ['skipped' => $result['skipped'] ?? 0, 'user_id' => $this->userId]
        );
    }
}
