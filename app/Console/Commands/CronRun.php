<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SyncShopifyOrders;
use App\Jobs\SyncStock;
use App\Jobs\SyncUyumSoftOrders;
use App\Jobs\SyncUyumSoftProducts;
use App\Jobs\UpdateCargoTracking;
use App\Models\SyncJob;
use App\Services\LogRetentionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Throwable;

class CronRun extends Command
{
    protected $signature = 'eticart:cron-run';

    protected $description = 'cPanel cron: tüm senkron ve kontrolleri kuyruğa atmadan sırayla çalıştırır';

    public function handle(): int
    {
        @ini_set('max_execution_time', '0');
        @set_time_limit(0);
        @ignore_user_abort(true);
        @ini_set('memory_limit', '512M');

        $startedAt = now();
        $this->logLine('eticart:cron-run START');

        Artisan::call('eticart:cron-heartbeat');
        $this->output->write(Artisan::output());

        $tasks = [
            'order_sync' => fn () => SyncShopifyOrders::dispatchSync(),
            'uyumsoft_order_sync' => fn () => SyncUyumSoftOrders::dispatchSync(),
            'stock_sync' => fn () => SyncStock::dispatchSync(),
            'product_sync' => fn () => SyncUyumSoftProducts::dispatchSync(50, 0, true, true),
            'cargo_tracking' => fn () => UpdateCargoTracking::dispatchSync(),
        ];

        $failed = 0;
        $shopifyRan = false;

        foreach ($tasks as $jobType => $runner) {
            $skipReason = $this->syncJobSkipReason($jobType);
            if ($jobType === 'uyumsoft_order_sync' && $skipReason !== null && $skipReason !== 'pasif' && $shopifyRan) {
                $skipReason = null;
            }

            if ($skipReason !== null) {
                $this->logLine("skip {$jobType} ({$skipReason})");
                continue;
            }

            $taskStarted = microtime(true);
            $this->logLine("run {$jobType}");

            try {
                $runner();
                if ($jobType === 'order_sync') {
                    $shopifyRan = true;
                }
                $seconds = round(microtime(true) - $taskStarted, 1);
                $this->logLine("ok {$jobType} ({$seconds}s)");
            } catch (Throwable $e) {
                $failed++;
                $this->logLine("FAIL {$jobType} — ".$e->getMessage());
            }
        }

        try {
            Artisan::call('eticart:process-queue');
            $this->output->write(Artisan::output());
        } catch (Throwable $e) {
            $this->logLine('FAIL process-queue — '.$e->getMessage());
        }

        try {
            $pruned = app(LogRetentionService::class)->pruneScheduled();
            if (! ($pruned['skipped'] ?? false) || ($pruned['files_pruned'] ?? false)) {
                $this->logLine(sprintf(
                    'prune-logs job_logs=%d activities=%d files=%s',
                    $pruned['sync_job_logs'],
                    $pruned['sync_activities'],
                    ($pruned['files_pruned'] ?? false) ? 'yes' : 'no'
                ));
            }
        } catch (Throwable $e) {
            $this->logLine('FAIL prune-logs — '.$e->getMessage());
        }

        $elapsed = $startedAt->diffInSeconds(now());
        $this->logLine("eticart:cron-run END — {$elapsed}s, fails={$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function syncJobSkipReason(string $jobType): ?string
    {
        try {
            $job = SyncJob::query()->where('job_type', $jobType)->first();
            if ($job === null) {
                return null;
            }

            if (! $job->is_active) {
                return 'pasif';
            }

            if ($job->next_run && $job->next_run->isFuture()) {
                return 'next_run';
            }

            $interval = max(1, (int) $job->interval_minutes);
            if ($job->last_run && $job->last_run->copy()->addMinutes($interval)->isFuture()) {
                return 'interval';
            }

            return null;
        } catch (Throwable) {
            return null;
        }
    }

    private function logLine(string $message): void
    {
        $line = '['.now()->toDateTimeString().'] '.$message;
        $this->line($line);
        @file_put_contents(storage_path('logs/cron.log'), $line.PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
