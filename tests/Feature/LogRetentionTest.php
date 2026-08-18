<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\SyncActivity;
use App\Models\SyncJob;
use App\Models\SyncJobLog;
use App\Models\User;
use App\Services\LogRetentionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LogRetentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_deletes_records_older_than_one_week(): void
    {
        $job = $this->syncJob();
        $oldLog = $this->syncLog($job, now()->subDays(8));
        $freshLog = $this->syncLog($job, now()->subDays(2));

        $oldActivity = $this->activity('completed', now()->subDays(10));
        $freshActivity = $this->activity('completed', now()->subDays(1));
        $runningOld = $this->activity(SyncActivity::STATUS_RUNNING, now()->subDays(20));

        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect();

        $this->assertDatabaseMissing('sync_job_logs', ['id' => $oldLog->id]);
        $this->assertDatabaseHas('sync_job_logs', ['id' => $freshLog->id]);
        $this->assertDatabaseMissing('sync_activities', ['id' => $oldActivity->id]);
        $this->assertDatabaseHas('sync_activities', ['id' => $freshActivity->id]);
        $this->assertDatabaseHas('sync_activities', ['id' => $runningOld->id]);
    }

    public function test_purge_all_sync_logs_from_reports_page(): void
    {
        $job = $this->syncJob();
        $this->syncLog($job, now());
        $this->syncLog($job, now()->subDay());

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('reports.sync-logs.purge-all'))
            ->assertRedirect(route('reports.sync-logs'))
            ->assertSessionHas('success');

        $this->assertSame(0, SyncJobLog::query()->count());
    }

    public function test_purge_failed_sync_logs_from_system_logs_page(): void
    {
        $job = $this->syncJob();
        $failed = $this->syncLog($job, now(), 'failed');
        $ok = $this->syncLog($job, now(), 'success');

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('reports.system-logs.purge-failed'))
            ->assertRedirect(route('reports.system-logs'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('sync_job_logs', ['id' => $failed->id]);
        $this->assertDatabaseHas('sync_job_logs', ['id' => $ok->id]);
    }

    public function test_forced_file_prune_empties_log_files(): void
    {
        $laravel = storage_path('logs/laravel-retention-test.log');
        $cron = storage_path('logs/cron-retention-test.log');

        file_put_contents($laravel, "keep-me\n");
        file_put_contents($cron, "keep-me\n");

        $service = new class extends LogRetentionService
        {
            protected function laravelLogPath(): string
            {
                return storage_path('logs/laravel-retention-test.log');
            }

            protected function cronLogPath(): string
            {
                return storage_path('logs/cron-retention-test.log');
            }
        };

        try {
            $this->assertTrue($service->pruneLogFilesIfDue(true));
            $this->assertSame('', file_get_contents($laravel));
            $this->assertSame('', file_get_contents($cron));
        } finally {
            @unlink($laravel);
            @unlink($cron);
        }
    }

    private function syncJob(): SyncJob
    {
        return SyncJob::query()->create([
            'job_type' => 'order_sync_test_'.uniqid(),
            'status' => 'idle',
            'interval_minutes' => 15,
            'is_active' => true,
        ]);
    }

    private function syncLog(SyncJob $job, $createdAt, string $status = 'success'): SyncJobLog
    {
        $log = SyncJobLog::query()->create([
            'sync_job_id' => $job->id,
            'status' => $status,
            'message' => 'test',
            'synced_count' => 1,
            'error_count' => $status === 'success' ? 0 : 1,
        ]);
        $log->created_at = $createdAt;
        $log->updated_at = $createdAt;
        $log->save();

        return $log;
    }

    private function activity(string $status, $createdAt): SyncActivity
    {
        $activity = SyncActivity::query()->create([
            'type' => 'order_sync',
            'title' => 'Test',
            'status' => $status,
            'message' => 'test',
        ]);
        $activity->created_at = $createdAt;
        $activity->updated_at = $createdAt;
        $activity->save();

        return $activity;
    }
}
