<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Cron minimum interval (minutes)
    |--------------------------------------------------------------------------
    |
    | Paylaşımlı hosting (cPanel) genelde en az 15 dakikalık cron destekler.
    | Scheduler ve ayarlardaki minimum aralık bu değere göre sınırlanır.
    |
    */
    'schedule_cron_minutes' => (int) env('SCHEDULE_CRON_MINUTES', 15),
];
