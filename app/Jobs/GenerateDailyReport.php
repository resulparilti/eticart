<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\SyncJob;
use App\Models\SyncJobLog;
use App\Services\ReportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class GenerateDailyReport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 300;

    /**
     * Execute the job.
     */
    public function handle(ReportService $reportService): void
    {
        $started = microtime(true);
        $date = now()->subDay()->toDateString();
        $sales = $reportService->getSalesReport($date, $date);
        $shipments = $reportService->getShipmentReport($date, $date);

        $payload = [
            'date' => $date,
            'orders' => $sales['summary']['orders'],
            'revenue' => $sales['summary']['revenue'],
            'shipments' => $shipments['summary']['total'],
            'generated_at' => now()->toDateTimeString(),
        ];

        $path = "reports/daily-{$date}.json";
        Storage::disk('local')->put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $syncJob = SyncJob::query()->firstOrCreate(
            ['job_type' => 'daily_report'],
            [
                'interval_minutes' => 1440,
                'status' => 'idle',
                'is_active' => true,
            ]
        );

        SyncJobLog::query()->create([
            'sync_job_id' => $syncJob->id,
            'status' => 'success',
            'message' => "Günlük rapor oluşturuldu: {$date}",
            'synced_count' => (int) $payload['orders'],
            'error_count' => 0,
            'duration' => round(microtime(true) - $started, 2),
            'error' => null,
        ]);

        $syncJob->update([
            'status' => 'idle',
            'last_run' => now(),
            'next_run' => now()->addDay(),
            'last_error' => null,
        ]);

        Log::channel('stack')->info('GenerateDailyReport completed', $payload);
    }

    public function failed(?Throwable $exception): void
    {
        Log::channel('stack')->error('GenerateDailyReport failed', [
            'message' => $exception?->getMessage(),
        ]);
    }
}
