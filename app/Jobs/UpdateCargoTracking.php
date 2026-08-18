<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\CargoService;
use App\Services\SyncActivityTracker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class UpdateCargoTracking implements ShouldQueue
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
    public function handle(CargoService $cargoService, SyncActivityTracker $tracker): void
    {
        $tracker->ensureFresh('cargo_tracking', 'Kargo durumu sorgulama');
        $tracker->markRunning('Açık kargolar Yurtiçi API üzerinden sorgulanıyor…');

        try {
            $result = $cargoService->updateTrackingStatus();
            $tracker->complete($result['message'] ?? 'Kargo sorgusu tamamlandı.', (int) ($result['updated'] ?? 0), (int) ($result['errors'] ?? 0));
            Log::channel('stack')->info('UpdateCargoTracking completed', $result);
        } catch (Throwable $e) {
            $tracker->fail($e->getMessage(), $e);
            throw $e;
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::channel('stack')->error('UpdateCargoTracking failed', [
            'message' => $exception?->getMessage(),
        ]);
    }
}
