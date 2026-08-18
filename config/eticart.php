<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Deployment mode
    |--------------------------------------------------------------------------
    |
    | vps: dakikalık cron, sık senkron aralıkları
    | shared: paylaşımlı hosting (min 15 dk cron)
    |
    */
    'deployment' => env('ETICART_DEPLOYMENT', 'vps'),

    /*
    |--------------------------------------------------------------------------
    | Cron minimum interval (minutes)
    |--------------------------------------------------------------------------
    */
    'schedule_cron_minutes' => (int) env(
        'SCHEDULE_CRON_MINUTES',
        env('ETICART_DEPLOYMENT', 'vps') === 'shared' ? 15 : 1
    ),

    /*
    |--------------------------------------------------------------------------
    | Sync interval options (minutes)
    |--------------------------------------------------------------------------
    */
    'interval_options' => [
        'vps' => [
            'orders' => [1, 2, 5, 10, 15],
            'products' => [5, 10, 15, 30, 60],
            'stock' => [1, 2, 5, 10, 15],
            'cargo' => [5, 10, 15, 30, 60],
        ],
        'shared' => [
            'orders' => [15, 30, 60],
            'products' => [15, 30, 60, 120],
            'stock' => [15, 30, 60],
            'cargo' => [15, 30, 60, 120],
        ],
    ],
];
