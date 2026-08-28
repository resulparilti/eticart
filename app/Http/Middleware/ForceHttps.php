<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Production'da HTTP isteklerini HTTPS'e 301 ile yönlendirir.
 * Yerel geliştirme (localhost / APP_ENV=local) etkilenmez.
 */
class ForceHttps
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->shouldRedirect($request)) {
            return redirect()->secure($request->getRequestUri(), 301);
        }

        if ($this->shouldForceHttpsUrls()) {
            URL::forceScheme('https');
        }

        return $next($request);
    }

    private function shouldRedirect(Request $request): bool
    {
        if ($request->secure() || $this->isLocalHost($request) || ! $this->isProduction()) {
            return false;
        }

        return true;
    }

    private function shouldForceHttpsUrls(): bool
    {
        return $this->isProduction();
    }

    private function isProduction(): bool
    {
        return app()->environment('production');
    }

    private function isLocalHost(Request $request): bool
    {
        $host = strtolower($request->getHost());

        return in_array($host, ['127.0.0.1', 'localhost', '::1'], true);
    }
}
