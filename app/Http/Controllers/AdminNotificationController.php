<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AdminNotification;
use App\Services\AdminNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminNotificationController extends Controller
{
    public function __construct(
        private readonly AdminNotificationService $notifications
    ) {
    }

    public function index(Request $request): View
    {
        $query = AdminNotification::query()->latest();

        if ($request->filled('type')) {
            $query->where('type', $request->string('type')->toString());
        }

        if ($request->string('unread')->toString() === '1') {
            $query->whereNull('read_at');
        }

        return view('alerts.index', [
            'notifications' => $query->paginate(30)->withQueryString(),
            'filters' => $request->only(['type', 'unread']),
            'types' => AdminNotification::typeLabels(),
            'unreadCount' => $this->notifications->unreadCount(),
            'breadcrumbs' => [
                ['label' => 'Anasayfa', 'url' => route('dashboard')],
                ['label' => 'Bildirimler'],
            ],
        ]);
    }

    public function latest(): JsonResponse
    {
        $items = $this->notifications->latestUnread(8)->map(static function (AdminNotification $item): array {
            return [
                'id' => $item->id,
                'type' => $item->type,
                'type_label' => $item->typeLabel(),
                'title' => $item->title,
                'message' => $item->message,
                'url' => $item->url,
                'icon' => $item->icon(),
                'unread' => $item->isUnread(),
                'created_at' => optional($item->created_at)->diffForHumans(),
            ];
        });

        return response()->json([
            'unread' => $this->notifications->unreadCount(),
            'items' => $items,
        ]);
    }

    public function markRead(AdminNotification $alert): RedirectResponse|JsonResponse
    {
        $this->notifications->markRead($alert);

        if (request()->wantsJson()) {
            return response()->json(['ok' => true]);
        }

        return redirect()->to($alert->url ?: route('alerts.index'));
    }

    public function markAllRead(): RedirectResponse
    {
        $this->notifications->markAllRead();

        return back()->with('success', 'Tüm bildirimler okundu olarak işaretlendi.');
    }

    public function destroy(AdminNotification $alert): RedirectResponse
    {
        $alert->delete();

        return redirect()
            ->route('alerts.index')
            ->with('success', 'Bildirim silindi.');
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $count = AdminNotification::query()
            ->whereIn('id', $validated['ids'])
            ->delete();

        return redirect()
            ->route('alerts.index')
            ->with('success', "{$count} bildirim silindi.");
    }
}
