<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class CpanelBootstrap extends Command
{
    /**
     * @var string
     */
    protected $signature = 'eticart:cpanel-bootstrap {--force : Onay istemeden migrate çalıştır}';

    /**
     * @var string
     */
    protected $description = 'cPanel kurulumu: migrate, storage link ve cache (composer sunucuda gerekmez)';

    public function handle(): int
    {
        $this->info('EtiCart cPanel kurulum adımları başlıyor…');

        if (! $this->option('force') && ! $this->confirm('Migrate ve optimize çalıştırılacak. Devam?', true)) {
            $this->warn('İptal edildi.');

            return self::FAILURE;
        }

        $this->call('migrate', ['--force' => true]);
        $this->call('storage:link');

        Artisan::call('config:cache');
        $this->line(Artisan::output());

        Artisan::call('route:cache');
        $this->line(Artisan::output());

        Artisan::call('view:cache');
        $this->line(Artisan::output());

        $cronMin = \App\Support\SyncIntervalOptions::minCronMinutes();
        $this->newLine();
        $this->info('Kurulum tamamlandı.');
        $this->line('cPanel Cron (her '.$cronMin.' dk):');
        $this->line('*/'.$cronMin.' * * * * cd '.base_path().' && php artisan schedule:run >> /dev/null 2>&1');

        return self::SUCCESS;
    }
}
