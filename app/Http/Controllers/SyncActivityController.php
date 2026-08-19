<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\SyncActivity;
use App\Services\LogRetentionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SyncActivityController extends Controller
{
    /**
     * Live monitor feed: only queued / running jobs.
     */
    public function live(Request $request): JsonResponse
    {
        $limit = min(20, max(5, (int) $request->integer('limit', 12)));

        $activities = SyncActivity::query()
            ->whereNull('dismissed_at')
            ->whereIn('status', [SyncActivity::STATUS_QUEUED, SyncActivity::STATUS_RUNNING])
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (SyncActivity $activity) => $activity->toMonitorArray(false))
            ->values();

        $activeCount = $activities->count();

        return response()->json([
            'active_count' => $activeCount,
            'activities' => $activities,
            'history_url' => route('sync-history.index'),
        ]);
    }

    /**
     * Single activity with logs (monitor API).
     */
    public function show(string $uuid): JsonResponse
    {
        $activity = SyncActivity::query()
            ->where('uuid', $uuid)
            ->with(['logs' => fn ($q) => $q->orderByDesc('id')->limit(200)])
            ->firstOrFail();

        $activity->setRelation('logs', $activity->logs->sortBy('id')->values());

        return response()->json($activity->toMonitorArray(true));
    }

    /**
     * Remove a finished activity from the floating monitor.
     */
    public function dismiss(string $uuid): JsonResponse
    {
        $activity = SyncActivity::query()->where('uuid', $uuid)->firstOrFail();

        if ($activity->isActive()) {
            return response()->json([
                'ok' => false,
                'message' => 'Çalışan işlem kapatılamaz.',
            ], 422);
        }

        $activity->dismiss();

        return response()->json([
            'ok' => true,
            'uuid' => $activity->uuid,
            'message' => 'İşlem izleyiciden kaldırıldı. Geçmiş menüsünden görüntüleyebilirsiniz.',
        ]);
    }

    /**
     * Dismiss all finished (non-active) activities from the monitor.
     */
    public function dismissFinished(): JsonResponse
    {
        $count = SyncActivity::query()
            ->whereNull('dismissed_at')
            ->whereNotIn('status', [SyncActivity::STATUS_QUEUED, SyncActivity::STATUS_RUNNING])
            ->update(['dismissed_at' => now()]);

        return response()->json([
            'ok' => true,
            'dismissed' => $count,
            'message' => "{$count} işlem izleyiciden temizlendi.",
        ]);
    }

    /**
     * Full history page (sidebar).
     */
    public function history(Request $request): View
    {
        $status = $request->string('status')->toString();
        $type = $request->string('type')->toString();
        $q = $request->string('q')->toString();

        try {
            app(LogRetentionService::class)->pruneDatabase();
        } catch (\Throwable) {
        }

        $retentionDays = LogRetentionService::RETENTION_DAYS;
        $query = SyncActivity::query()
            ->where('created_at', '>=', now()->subDays($retentionDays))
            ->latest('id');

        if ($status !== '') {
            $query->where('status', $status);
        }
        if ($type !== '') {
            $query->where('type', $type);
        }
        if ($q !== '') {
            $query->where(function ($builder) use ($q) {
                $builder->where('title', 'like', "%{$q}%")
                    ->orWhere('message', 'like', "%{$q}%");
            });
        }

        return view('sync-history.index', [
            'activities' => $query->paginate(30)->withQueryString(),
            'retentionDays' => $retentionDays,
            'status' => $status,
            'type' => $type,
            'search' => $q,
            'types' => SyncActivity::query()->select('type')->distinct()->orderBy('type')->pluck('type'),
            'breadcrumbs' => [
                ['label' => 'Anasayfa', 'url' => route('dashboard')],
                ['label' => 'İşlem Geçmişi'],
            ],
        ]);
    }

    /**
     * History detail with logs.
     */
    public function historyShow(string $uuid): View
    {
        $activity = SyncActivity::query()
            ->where('uuid', $uuid)
            ->with(['logs' => fn ($q) => $q->orderBy('id')])
            ->firstOrFail();

        return view('sync-history.show', [
            'activity' => $activity,
            'logs' => $activity->logs,
            'breadcrumbs' => [
                ['label' => 'Anasayfa', 'url' => route('dashboard')],
                ['label' => 'İşlem Geçmişi', 'url' => route('sync-history.index')],
                ['label' => $activity->title],
            ],
        ]);
    }

    /**
     * Toplu işlem geçmişi silme.
     */
    public function bulkDestroy(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $query = SyncActivity::query()
            ->whereIn('id', $validated['ids'])
            ->whereNotIn('status', [SyncActivity::STATUS_QUEUED, SyncActivity::STATUS_RUNNING]);

        $count = (clone $query)->count();
        $query->delete();

        return redirect()
            ->route('sync-history.index')
            ->with('success', "{$count} işlem geçmişi kaydı silindi.");
    }

    /**
     * Filtrelere göre veya tüm bitmiş kayıtları sil.
     */
    public function destroyFiltered(Request $request): RedirectResponse
    {
        $mode = $request->string('mode')->toString() ?: 'filtered';

        $query = SyncActivity::query()
            ->whereNotIn('status', [SyncActivity::STATUS_QUEUED, SyncActivity::STATUS_RUNNING]);

        if ($mode === 'filtered') {
            $status = $request->string('status')->toString();
            $type = $request->string('type')->toString();
            $q = $request->string('q')->toString();

            if ($status !== '') {
                $query->where('status', $status);
            }
            if ($type !== '') {
                $query->where('type', $type);
            }
            if ($q !== '') {
                $query->where(function ($builder) use ($q) {
                    $builder->where('title', 'like', "%{$q}%")
                        ->orWhere('message', 'like', "%{$q}%");
                });
            }
        }

        $count = (clone $query)->count();
        $query->delete();

        return redirect()
            ->route('sync-history.index')
            ->with('success', "{$count} işlem geçmişi kaydı silindi.");
    }
}
