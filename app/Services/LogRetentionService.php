<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Setting;
use App\Models\SyncActivity;
use App\Models\SyncJobLog;
use Illuminate\Support\Facades\Log;
use Throwable;

class LogRetentionService
{
    public const RETENTION_DAYS = 7;

    public const FILE_PRUNE_SECONDS = 604800;

    /**
     * Login: 1 haftadan eski DB kayıtlarını sil, log dosyalarını haftalık boşalt.
     *
     * @return array{sync_job_logs: int, sync_activities: int, files_pruned: bool}
     */
    public function pruneOnLogin(): array
    {
        $result = $this->pruneDatabase();
        $result['files_pruned'] = $this->pruneLogFilesIfDue();

        return $result;
    }

    /**
     * Cron yedeği: DB her gün, dosyalar haftada bir.
     *
     * @return array{sync_job_logs: int, sync_activities: int, files_pruned: bool, skipped: bool}
     */
    public function pruneScheduled(): array
    {
        $today = now()->toDateString();
        $last = '';
        try {
            $last = trim((string) Setting::getValue('log_retention_last_db_prune', ''));
        } catch (Throwable) {
        }

        $db = ['sync_job_logs' => 0, 'sync_activities' => 0];
        if ($last !== $today) {
            $db = $this->pruneDatabase();
            try {
                Setting::setValue('log_retention_last_db_prune', $today, 'system', 'Son log DB temizliği');
            } catch (Throwable) {
            }
        }

        return [
            'sync_job_logs' => $db['sync_job_logs'],
            'sync_activities' => $db['sync_activities'],
            'files_pruned' => $this->pruneLogFilesIfDue(),
            'skipped' => $last === $today,
        ];
    }

    /**
     * @return array{sync_job_logs: int, sync_activities: int}
     */
    public function pruneDatabase(): array
    {
        $cutoff = now()->subDays(self::RETENTION_DAYS);

        return [
            'sync_job_logs' => $this->deleteOldSyncJobLogs($cutoff),
            'sync_activities' => $this->deleteOldSyncActivities($cutoff),
        ];
    }

    public function purgeAllSyncJobLogs(): int
    {
        $count = (int) SyncJobLog::query()->count();
        SyncJobLog::query()->delete();

        return $count;
    }

    public function purgeFailedSyncJobLogs(): int
    {
        $query = SyncJobLog::query()->where(function ($q) {
            $q->where('status', '!=', 'success')
                ->orWhere('error_count', '>', 0);
        });

        $count = (int) (clone $query)->count();
        $query->delete();

        return $count;
    }

    public function pruneLogFilesIfDue(bool $force = false): bool
    {
        if (! $force && ! $this->logFilesAreDue()) {
            return false;
        }

        try {
            $last = (int) Setting::getValue('log_files_last_pruned_at', 0);
        } catch (Throwable) {
            $last = 0;
        }

        if (! $force && $last <= 0) {
            try {
                Setting::setValue('log_files_last_pruned_at', (string) time(), 'system', 'Son log dosyası temizliği');
            } catch (Throwable) {
            }

            return false;
        }

        $this->truncateLogFile($this->laravelLogPath());
        $this->truncateLogFile($this->cronLogPath());

        try {
            Setting::setValue('log_files_last_pruned_at', (string) time(), 'system', 'Son log dosyası temizliği');
        } catch (Throwable $e) {
            Log::warning('Log file prune timestamp could not be saved', [
                'message' => $e->getMessage(),
            ]);
        }

        return true;
    }

    private function logFilesAreDue(): bool
    {
        try {
            $last = (int) Setting::getValue('log_files_last_pruned_at', 0);
        } catch (Throwable) {
            $last = 0;
        }

        if ($last <= 0) {
            return true;
        }

        return (time() - $last) >= self::FILE_PRUNE_SECONDS;
    }

    private function deleteOldSyncJobLogs(\DateTimeInterface $cutoff): int
    {
        try {
            return (int) SyncJobLog::query()
                ->where('created_at', '<', $cutoff)
                ->delete();
        } catch (Throwable $e) {
            Log::warning('Old sync job logs could not be pruned', [
                'message' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    private function deleteOldSyncActivities(\DateTimeInterface $cutoff): int
    {
        try {
            return (int) SyncActivity::query()
                ->where('created_at', '<', $cutoff)
                ->whereNotIn('status', [SyncActivity::STATUS_QUEUED, SyncActivity::STATUS_RUNNING])
                ->delete();
        } catch (Throwable $e) {
            Log::warning('Old sync activities could not be pruned', [
                'message' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    protected function laravelLogPath(): string
    {
        return storage_path('logs/laravel.log');
    }

    protected function cronLogPath(): string
    {
        return storage_path('logs/cron.log');
    }

    private function truncateLogFile(string $path): void
    {
        if (! is_file($path)) {
            return;
        }

        $handle = @fopen($path, 'c');
        if ($handle === false) {
            return;
        }

        try {
            if (flock($handle, LOCK_EX)) {
                ftruncate($handle, 0);
                fflush($handle);
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }
}
