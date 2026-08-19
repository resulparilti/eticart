<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\SyncActivity;
use App\Models\User;
use App\Services\SyncActivityTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncActivityMonitorTest extends TestCase
{
    use RefreshDatabase;

    public function test_live_monitor_lists_only_queued_and_running_jobs(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        SyncActivity::query()->create([
            'type' => 'shopify_pull',
            'title' => 'Devam eden',
            'status' => SyncActivity::STATUS_RUNNING,
            'message' => 'çekiliyor',
        ]);
        SyncActivity::query()->create([
            'type' => 'shopify_push',
            'title' => 'Bekleyen',
            'status' => SyncActivity::STATUS_QUEUED,
            'message' => 'kuyruk',
        ]);
        SyncActivity::query()->create([
            'type' => 'shopify_push',
            'title' => 'Bitti',
            'status' => SyncActivity::STATUS_COMPLETED,
            'message' => 'tamam',
            'finished_at' => now(),
            'dismissed_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->getJson(route('sync-activities.live'))
            ->assertOk();

        $titles = collect($response->json('activities'))->pluck('title');
        $this->assertTrue($titles->contains('Devam eden'));
        $this->assertTrue($titles->contains('Bekleyen'));
        $this->assertFalse($titles->contains('Bitti'));
        $this->assertSame(2, $response->json('active_count'));
    }

    public function test_completed_activity_is_dismissed_from_monitor(): void
    {
        $tracker = app(SyncActivityTracker::class);
        $activity = $tracker->start('shopify_pull', 'Tek ürün çek');
        $tracker->markRunning('çalışıyor');
        $tracker->complete('bitti', 1, 0);

        $activity->refresh();
        $this->assertSame(SyncActivity::STATUS_COMPLETED, $activity->status);
        $this->assertNotNull($activity->dismissed_at);
    }
}
