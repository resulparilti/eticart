<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Giriş ve panel sayfalarının tarayıcı önbelleğinde kalmasını önler.
 * Geri tuşuyla eski login HTML'inin gösterilmesini engeller.
 */
class DisableBrowserCache
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        if ($this->shouldSkip($request, $response)) {
            return $response;
        }

        $response->headers->set('Cache-Control', 'no-store, no-cache, max-age=0, must-revalidate, private');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');

        return $response;
    }

    private function shouldSkip(Request $request, Response $response): bool
    {
        if ($request->isMethod('HEAD')) {
            return true;
        }

        $contentType = (string) $response->headers->get('Content-Type', '');

        return $contentType !== ''
            && ! str_contains($contentType, 'text/html')
            && ! str_contains($contentType, 'text/plain');
    }
}
