<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\PermissionCatalog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureModulePermission
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $permission = PermissionCatalog::forRoute($request->route()?->getName());
        if ($permission === null) {
            return $next($request);
        }

        if (! PermissionCatalog::allows($request->user(), $permission)) {
            abort(403, 'Bu sayfa veya işlem için yetkiniz yok.');
        }

        return $next($request);
    }
}
