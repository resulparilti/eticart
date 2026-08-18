<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\SyncShopifyOrders;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class QueueMonitorController extends Controller
{
    /**
     * Queue status dashboard.
     */
    public function index(): View
    {
        $pending = DB::table('jobs')->count();
        $failed = DB::table('failed_jobs')->count();

        $recentJobs = DB::table('jobs')
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(function ($job) {
                $payload = json_decode($job->payload ?? '{}', true) ?: [];

                return (object) [
                    'id' => $job->id,
                    'queue' => $job->queue,
                    'attempts' => $job->attempts,
                    'display_name' => $payload['displayName'] ?? ($payload['data']['commandName'] ?? 'Job'),
                    'available_at' => $job->available_at
                        ? date('d.m.Y H:i', (int) $job->available_at)
                        : null,
                    'created_at' => $job->created_at
                        ? date('d.m.Y H:i', (int) $job->created_at)
                        : null,
                ];
            });

        $failedJobs = DB::table('failed_jobs')
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(function ($job) {
                $payload = json_decode($job->payload ?? '{}', true) ?: [];

                return (object) [
                    'id' => $job->id,
                    'uuid' => $job->uuid,
                    'queue' => $job->queue,
                    'display_name' => $payload['displayName'] ?? 'Failed Job',
                    'exception' => mb_substr((string) $job->exception, 0, 300),
                    'failed_at' => $job->failed_at,
                ];
            });

        return view('admin.queue-status', [
            'connection' => config('queue.default'),
            'pending' => $pending,
            'failed' => $failed,
            'recentJobs' => $recentJobs,
            'failedJobs' => $failedJobs,
            'breadcrumbs' => [
                ['label' => 'Dashboard', 'url' => route('dashboard')],
                ['label' => 'Queue Durumu'],
            ],
        ]);
    }

    /**
     * Retry a failed job.
     */
    public function retry(string $uuid): RedirectResponse
    {
        Artisan::call('queue:retry', ['id' => [$uuid]]);

        return back()->with('success', 'Job yeniden kuyruğa alındı.');
    }

    /**
     * Retry all failed jobs.
     */
    public function retryAll(): RedirectResponse
    {
        Artisan::call('queue:retry', ['id' => ['all']]);

        return back()->with('success', 'Tüm başarısız joblar yeniden kuyruğa alındı.');
    }

    /**
     * Flush failed jobs.
     */
    public function flushFailed(): RedirectResponse
    {
        Artisan::call('queue:flush');

        return back()->with('success', 'Başarısız joblar temizlendi.');
    }

    /**
     * Bekleyen (işlenmemiş) kuyruk kayıtlarını sil.
     * Cron sync job'ları dispatchSync ile çalışmalı; eski Kernel biriktirmiş olabilir.
     */
    public function clearPending(): RedirectResponse
    {
        $count = DB::table('jobs')->count();
        DB::table('jobs')->delete();

        return back()->with('success', "{$count} bekleyen kuyruk kaydı silindi. Yeni cron turları dispatchSync ile doğrudan çalışır.");
    }

    /**
     * Kuyruğu şimdi işle (eticart:process-queue).
     */
    public function processNow(): RedirectResponse
    {
        $before = DB::table('jobs')->count();

        try {
            Artisan::call('eticart:process-queue');
            $output = trim(Artisan::output());
        } catch (\Throwable $e) {
            return back()->with('error', 'Kuyruk işlenemedi: '.$e->getMessage());
        }

        $after = DB::table('jobs')->count();

        return back()->with(
            'success',
            "Kuyruk işlendi: {$before} → {$after} bekleyen. ".($output !== '' ? $output : '')
        );
    }

    /**
     * Dispatch a sample sync job for local testing.
     */
    public function dispatchTest(Request $request): RedirectResponse
    {
        // Paylaşımlı hostingte kuyruk birikmesin diye sync çalıştır.
        SyncShopifyOrders::dispatchSync(10, 'any');

        return back()->with('success', 'Test: SyncShopifyOrders anında (dispatchSync) çalıştırıldı.');
    }
}
