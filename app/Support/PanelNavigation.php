<?php

declare(strict_types=1);

namespace App\Support;

use App\Providers\RouteServiceProvider;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Panel içinde dolaşılan GET sayfalarını oturumda tutar.
 * Giriş sayfasına düşüldüğünde kullanıcıyı son panel sayfasına döndürmek için kullanılır.
 */
final class PanelNavigation
{
    public const HISTORY_KEY = 'panel.history';

    public const LAST_KEY = 'panel.last_url';

    private const MAX_ENTRIES = 20;

    /**
     * Kimliği doğrulanmış kullanıcının ziyaret ettiği panel sayfasını kaydet.
     */
    public static function record(Request $request): void
    {
        if (! $request->user() || ! $request->isMethod('GET') || ! $request->hasSession()) {
            return;
        }

        if (self::shouldIgnoreRequest($request)) {
            return;
        }

        $url = $request->fullUrl();
        $history = array_values(array_filter(
            $request->session()->get(self::HISTORY_KEY, []),
            static fn ($entry): bool => is_string($entry) && $entry !== ''
        ));

        $last = $history[array_key_last($history)] ?? null;
        if ($last !== $url) {
            $history[] = $url;
            $history = array_slice($history, -self::MAX_ENTRIES);
            $request->session()->put(self::HISTORY_KEY, $history);
        }

        $request->session()->put(self::LAST_KEY, $url);
        $request->session()->put('url.intended', $url);
    }

    /**
     * Giriş/kayıt gibi auth sayfalarına düşmeden dönülecek son panel adresi.
     */
    public static function last(Request $request, ?string $fallback = null): string
    {
        $fallback ??= RouteServiceProvider::HOME;
        $current = $request->fullUrl();
        $history = $request->hasSession()
            ? $request->session()->get(self::HISTORY_KEY, [])
            : [];

        if (is_array($history)) {
            for ($i = count($history) - 1; $i >= 0; $i--) {
                $candidate = $history[$i];
                if (! is_string($candidate) || $candidate === '' || $candidate === $current) {
                    continue;
                }

                $safe = self::sanitize($request, $candidate, '');
                if ($safe !== '') {
                    return $safe;
                }
            }
        }

        $stored = $request->hasSession() ? (string) $request->session()->get(self::LAST_KEY, '') : '';
        $safeStored = self::sanitize($request, $stored, '');
        if ($safeStored !== '' && $safeStored !== $current) {
            return $safeStored;
        }

        $intended = $request->hasSession() ? (string) $request->session()->get('url.intended', '') : '';
        $safeIntended = self::sanitize($request, $intended, '');
        if ($safeIntended !== '' && $safeIntended !== $current) {
            return $safeIntended;
        }

        return self::sanitize($request, $fallback, RouteServiceProvider::HOME);
    }

    /**
     * Tarayıcı geçmişindeki login kaydını ezerek hedefe git (yönlendirme döngüsünü kırar).
     */
    public static function replaceResponse(Request $request, ?string $url = null): Response
    {
        $target = self::sanitize($request, $url ?? self::last($request), RouteServiceProvider::HOME);

        return response()
            ->view('auth.history-replace', ['url' => $target])
            ->header('Cache-Control', 'no-store, no-cache, max-age=0, must-revalidate, private')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');
    }

    /**
     * Açık yönlendirmeyi engelleyen iç URL doğrulaması.
     */
    public static function sanitize(Request $request, string $url, string $fallback): string
    {
        $url = trim($url);
        if ($url === '' || str_contains($url, "\n") || str_contains($url, "\r") || str_starts_with($url, '//')) {
            return $fallback;
        }

        $path = '';
        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');
        } else {
            $root = rtrim($request->root(), '/');
            if ($url !== $root && ! str_starts_with($url, $root.'/')) {
                return $fallback;
            }

            $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');
        }

        if (self::isExcludedPath($path)) {
            return $fallback;
        }

        return $url;
    }

    public static function isExcludedPath(string $path): bool
    {
        $path = '/'.ltrim($path, '/');
        if ($path !== '/') {
            $path = rtrim($path, '/') ?: '/';
        }

        $exact = [
            '/',
            '/login',
            '/register',
            '/logout',
            '/session/status',
            '/forgot-password',
            '/confirm-password',
        ];

        if (in_array($path, $exact, true)) {
            return true;
        }

        foreach ([
            '/reset-password',
            '/verify-email',
            '/email',
            '/livewire',
            '/fatura',
            '/f',
            '/shopify',
            '/_debugbar',
            '/telescope',
            '/horizon',
            '/sanctum',
        ] as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }

    private static function shouldIgnoreRequest(Request $request): bool
    {
        if (self::isExcludedPath($request->getPathInfo())) {
            return true;
        }

        if ($request->ajax() || $request->pjax() || $request->prefetch() || $request->expectsJson()) {
            return true;
        }

        if ($request->headers->get('X-Livewire') || $request->headers->has('HX-Request')) {
            return true;
        }

        $purpose = strtolower((string) $request->headers->get('Sec-Purpose', $request->headers->get('Purpose', '')));
        if (str_contains($purpose, 'prefetch')) {
            return true;
        }

        $accept = strtolower((string) $request->headers->get('Accept', ''));

        return $accept !== ''
            && ! str_contains($accept, 'text/html')
            && ! str_contains($accept, '*/*');
    }
}
