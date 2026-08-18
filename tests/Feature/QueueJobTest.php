<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\GenerateDailyReport;
use App\Jobs\SyncShopifyOrders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class QueueJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_job_can_be_dispatched(): void
    {
        Queue::fake();

        SyncShopifyOrders::dispatch(5);

        Queue::assertPushed(SyncShopifyOrders::class, function (SyncShopifyOrders $job) {
            return $job->limit === 5;
        });
    }

    public function test_daily_report_job_can_be_dispatched(): void
    {
        Queue::fake();

        GenerateDailyReport::dispatch();

        Queue::assertPushed(GenerateDailyReport::class);
    }
}
