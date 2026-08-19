<?php

namespace App\Console;

use App\Jobs\GenerateDailyReport;
use App\Support\SyncIntervalOptions;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        $cronMinutes = SyncIntervalOptions::minCronMinutes();

        // cPanel cron ~15 dk'da bir schedule:run çağırır.
        // everyFifteenMinutes() :00/:15 dışında "No scheduled commands" verir → everyMinute().
        // Tüm senkronlar dispatchSync ile bu turda biter; kuyruğa bırakılmaz.
        $schedule->command('eticart:cron-run')
            ->everyMinute()
            ->withoutOverlapping($cronMinutes + 10);

        $schedule->call(fn () => GenerateDailyReport::dispatchSync())
            ->name(GenerateDailyReport::class)
            ->dailyAt('02:00')
            ->withoutOverlapping();

        $schedule->command('eticart:prune-logs')
            ->dailyAt('03:10')
            ->withoutOverlapping();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
