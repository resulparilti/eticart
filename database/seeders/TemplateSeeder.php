<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\SyncJob;
use App\Support\OrderMessageTemplates;
use Illuminate\Database\Seeder;

class TemplateSeeder extends Seeder
{
    /**
     * Seed mail/SMS templates and sync jobs.
     */
    public function run(): void
    {
        OrderMessageTemplates::syncToDatabase();

        $syncJobs = [
            ['job_type' => 'order_sync', 'interval_minutes' => 5],
            ['job_type' => 'product_sync', 'interval_minutes' => 15],
            ['job_type' => 'stock_sync', 'interval_minutes' => 5],
            ['job_type' => 'cargo_tracking', 'interval_minutes' => 15],
            ['job_type' => 'uyumsoft_order_sync', 'interval_minutes' => 5],
        ];

        foreach ($syncJobs as $job) {
            SyncJob::query()->updateOrCreate(
                ['job_type' => $job['job_type']],
                [
                    'interval_minutes' => $job['interval_minutes'],
                    'status' => 'idle',
                    'is_active' => true,
                ]
            );
        }
    }
}
