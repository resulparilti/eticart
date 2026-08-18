<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessQueue extends Command
{
    protected $signature = 'eticart:process-queue';

    protected $description = 'Paylaşımlı hosting cron için database kuyruğunu güvenli işler';

    public function handle(): int
    {
        $startedAt = now();
        $logFile = storage_path('logs/cron.log');

        $pending = $this->pendingJobCount();
        $failed = $this->failedJobCount();

        try {
            /** @var Worker $worker */
            $worker = app('queue.worker');

            $exitCode = (int) $worker
                ->setName('eticart-cron')
                ->daemon('database', 'default', new WorkerOptions(
                    'eticart-cron',
                    0,
                    256,
                    600,
                    1,
                    3,
                    false,
                    true,
                    25,
                    0,
                    0
                ));

            $remaining = $this->pendingJobCount();
            $failedAfter = $this->failedJobCount();

            $line = sprintf(
                '[%s] eticart:process-queue OK — exit %d, pending %d→%d, failed %d→%d',
                $startedAt->toDateTimeString(),
                $exitCode,
                $pending,
                $remaining,
                $failed,
                $failedAfter
            );

            @file_put_contents($logFile, $line.PHP_EOL, FILE_APPEND | LOCK_EX);
            $this->line($line);

            if ($exitCode === Worker::EXIT_MEMORY_LIMIT) {
                Log::warning('Queue worker stopped: memory limit', [
                    'pending_before' => $pending,
                    'pending_after' => $remaining,
                ]);

                return self::SUCCESS;
            }

            return $exitCode === Worker::EXIT_SUCCESS ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $e) {
            $line = sprintf(
                '[%s] eticart:process-queue FAIL — %s',
                $startedAt->toDateTimeString(),
                $e->getMessage()
            );

            @file_put_contents($logFile, $line.PHP_EOL, FILE_APPEND | LOCK_EX);
            Log::error('eticart:process-queue exception', [
                'message' => $e->getMessage(),
                'pending' => $pending,
            ]);

            $this->error($line);

            return self::FAILURE;
        }
    }

    private function pendingJobCount(): int
    {
        try {
            return (int) DB::table('jobs')->count();
        } catch (Throwable) {
            return -1;
        }
    }

    private function failedJobCount(): int
    {
        try {
            return (int) DB::table('failed_jobs')->count();
        } catch (Throwable) {
            return -1;
        }
    }
}
