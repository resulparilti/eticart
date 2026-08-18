<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\LogRetentionService;
use Illuminate\Console\Command;
use Throwable;

class PruneLogs extends Command
{
    protected $signature = 'eticart:prune-logs {--files : laravel.log ve cron.log dosyalarını şimdi boşalt}';

    protected $description = '1 haftadan eski senkron / işlem geçmişi kayıtlarını siler; log dosyalarını haftalık boşaltır';

    public function handle(LogRetentionService $retention): int
    {
        try {
            $result = $retention->pruneScheduled();
            if ($this->option('files')) {
                $retention->pruneLogFilesIfDue(true);
                $result['files_pruned'] = true;
            }
        } catch (Throwable $e) {
            $this->error('Log temizliği başarısız: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Senkron log: %d silindi · İşlem geçmişi: %d silindi · Dosyalar: %s',
            $result['sync_job_logs'],
            $result['sync_activities'],
            ($result['files_pruned'] ?? false) ? 'temizlendi' : 'bekleniyor'
        ));

        return self::SUCCESS;
    }
}
