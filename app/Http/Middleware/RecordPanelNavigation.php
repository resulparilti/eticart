<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\PanelNavigation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Giriş yapmış kullanıcının dolaştığı panel sayfalarını oturumda biriktirir.
 */
class RecordPanelNavigation
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        PanelNavigation::record($request);

        return $next($request);
    }
}
