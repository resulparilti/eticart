<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shopify admin iframe + HTTPS tünel isteklerinde oturum çerezinin tarayıcı
 * tarafından düşürülmesini engeller (419 Page Expired).
 */
class ConfigureCrossSiteSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->shouldUseCrossSiteCookies($request)) {
            config([
                'session.secure' => true,
                'session.same_site' => 'none',
                'app.url' => $request->getSchemeAndHttpHost(),
            ]);

            URL::forceScheme('https');
            URL::forceRootUrl($request->getSchemeAndHttpHost());
        }

        /** @var Response $response */
        $response = $next($request);

        if ($this->allowsShopifyFrame($request)) {
            $response->headers->remove('X-Frame-Options');
            $response->headers->set(
                'Content-Security-Policy',
                "frame-ancestors 'self' https://admin.shopify.com https://*.myshopify.com https://*.shopify.com"
            );
        }

        return $response;
    }

    private function shouldUseCrossSiteCookies(Request $request): bool
    {
        if ($this->isLocalHost($request)) {
            return false;
        }

        return $request->secure()
            && ($this->isTunnelHost($request)
                || $this->matchesShopifyAppHost($request)
                || $this->isShopifyRequest($request));
    }

    private function matchesShopifyAppHost(Request $request): bool
    {
        $appUrl = (string) config('services.shopify.app_url', '');
        if ($appUrl === '') {
            return false;
        }

        $appHost = strtolower((string) parse_url($appUrl, PHP_URL_HOST));

        return $appHost !== '' && $appHost === strtolower($request->getHost());
    }

    private function allowsShopifyFrame(Request $request): bool
    {
        return $this->isShopifyRequest($request) || $this->isTunnelHost($request);
    }

    private function isShopifyRequest(Request $request): bool
    {
        if ($request->filled('shop') || $request->filled('hmac') || $request->filled('host')) {
            return true;
        }

        if ($request->boolean('embedded') || $request->is('shopify', 'shopify/*')) {
            return true;
        }

        $referer = (string) $request->headers->get('referer', '');

        return $referer !== '' && (bool) preg_match(
            '#https?://([^/]+\.)?(myshopify\.com|shopify\.com|shopifycloud\.com)#i',
            $referer
        );
    }

    private function isTunnelHost(Request $request): bool
    {
        $host = strtolower($request->getHost());

        foreach (['.trycloudflare.com', '.ngrok-free.app', '.ngrok.io', '.ngrok.app', '.loca.lt'] as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }

    private function isLocalHost(Request $request): bool
    {
        $host = strtolower($request->getHost());

        return in_array($host, ['127.0.0.1', 'localhost', '::1'], true);
    }
}
