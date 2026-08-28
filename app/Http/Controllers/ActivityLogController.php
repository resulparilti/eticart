<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ActivityLogController extends Controller
{
    public function index(Request $request): View
    {
        $query = ActivityLog::query()->with('user')->latest('created_at');

        if ($request->filled('q')) {
            $q = $request->string('q')->toString();
            $query->where(function ($builder) use ($q) {
                $builder->where('user_name', 'like', '%'.$q.'%')
                    ->orWhere('user_email', 'like', '%'.$q.'%')
                    ->orWhere('description', 'like', '%'.$q.'%')
                    ->orWhere('path', 'like', '%'.$q.'%');
            });
        }

        if ($request->filled('action')) {
            $query->where('action', $request->string('action')->toString());
        }

        if ($request->filled('module')) {
            $query->where('module', $request->string('module')->toString());
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->date('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->date('to'));
        }

        return view('activity-logs.index', [
            'logs' => $query->paginate(40)->withQueryString(),
            'filters' => $request->only(['q', 'action', 'module', 'from', 'to']),
            'actions' => ['view' => 'Listeleme', 'create' => 'Ekleme', 'update' => 'Düzenleme', 'delete' => 'Silme', 'prepare' => 'Hazırlama', 'login' => 'Giriş', 'logout' => 'Çıkış'],
            'modules' => collect(\App\Support\PermissionCatalog::modules())->mapWithKeys(
                fn (array $meta, string $key): array => [$key => $meta['label']]
            )->all(),
            'breadcrumbs' => [
                ['label' => 'Anasayfa', 'url' => route('dashboard')],
                ['label' => 'İşlem kayıtları'],
            ],
        ]);
    }
}
