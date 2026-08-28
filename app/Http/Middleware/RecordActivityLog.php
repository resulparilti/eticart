<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\ActivityLogService;
use App\Support\PermissionCatalog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RecordActivityLog
{
    public function __construct(
        private readonly ActivityLogService $logs
    ) {
    }

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $this->shouldRecord($request, $response)) {
            return $response;
        }

        $routeName = (string) $request->route()?->getName();
        $permission = PermissionCatalog::forRoute($routeName);
        $module = $permission ? explode('.', $permission)[0] : $this->guessModule($routeName);
        $action = $this->actionFromRequest($request, $permission);
        $description = $this->describe($request, $action, $routeName);

        $this->logs->record(
            $action,
            $description,
            $request->user(),
            $module,
            $request,
            null,
            [
                'status' => $response->getStatusCode(),
                'query' => $request->query(),
            ]
        );

        return $response;
    }

    private function shouldRecord(Request $request, Response $response): bool
    {
        if (! $request->user()) {
            return false;
        }

        $status = $response->getStatusCode();
        if ($status >= 400) {
            return false;
        }

        $routeName = (string) $request->route()?->getName();
        if ($this->isNoisyRoute($routeName, $request)) {
            return false;
        }

        $method = strtoupper($request->method());

        return in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }

    private function isNoisyRoute(string $routeName, Request $request): bool
    {
        $skip = [
            'sync-activities.live',
            'sync-activities.dismiss',
            'sync-activities.dismiss-finished',
            'alerts.latest',
            'session.status',
            'login',
            'logout',
            'orders.packing.checklist',
            'orders.packing.complete',
        ];

        if (in_array($routeName, $skip, true)) {
            return true;
        }

        $path = $request->path();

        return str_starts_with($path, 'livewire')
            || str_contains($path, '_debugbar')
            || str_contains($path, 'horizon');
    }

    private function actionFromRequest(Request $request, ?string $permission): string
    {
        if ($permission && str_contains($permission, '.')) {
            return explode('.', $permission)[1];
        }

        return match (strtoupper($request->method())) {
            'POST' => 'create',
            'PUT', 'PATCH' => 'update',
            'DELETE' => 'delete',
            default => 'view',
        };
    }

    private function guessModule(string $routeName): ?string
    {
        $prefix = explode('.', $routeName)[0] ?? '';

        return $prefix !== '' ? $prefix : null;
    }

    private function describe(Request $request, string $action, string $routeName): string
    {
        $labels = PermissionCatalog::ACTIONS;
        $actionLabel = $labels[$action] ?? $action;
        $page = $this->pageLabel($routeName);

        return "{$actionLabel} işlemini yaptı. {$page} (rota: {$routeName}).";
    }

    private function pageLabel(string $routeName): string
    {
        $module = explode('.', $routeName)[0] ?? '';
        $modules = PermissionCatalog::modules();

        return $modules[$module]['label'] ?? str_replace(['.', '-'], ' ', $routeName);
    }
}
