<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CronHeartbeat extends Command
{
    protected $signature = 'eticart:cron-heartbeat';

    protected $description = 'Cron tetiklendiğinde son çalışma zamanını kaydeder (doğrulama için)';

    public function handle(): int
    {
        $now = now();

        try {
            Setting::setValue('cron_last_heartbeat', $now->toIso8601String(), 'system', 'Son cron heartbeat');
        } catch (\Throwable $e) {
            Log::warning('Cron heartbeat setting write failed', ['message' => $e->getMessage()]);
        }

        $line = '['.$now->toDateTimeString().'] schedule:run OK — pid '.getmypid();
        Log::channel('stack')->info('Cron heartbeat', ['at' => $now->toIso8601String()]);

        $logFile = storage_path('logs/cron.log');
        @file_put_contents($logFile, $line.PHP_EOL, FILE_APPEND | LOCK_EX);

        $this->line($line);

        return self::SUCCESS;
    }
}
