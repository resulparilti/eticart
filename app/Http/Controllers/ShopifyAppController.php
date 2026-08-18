<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\ShopifyException;
use App\Services\ShopifyOAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class ShopifyAppController extends Controller
{
    public function __construct(
        private readonly ShopifyOAuthService $oauth
    ) {
    }

    /**
     * Shopify "App URL" — mağaza uygulamayı açınca buraya gelir.
     */
    public function entry(Request $request): View|RedirectResponse
    {
        $shop = (string) $request->input('shop', '');

        if ($shop !== '' && $this->oauth->isInstalledForShop($shop)) {
            return redirect('/dashboard');
        }

        if ($shop !== '') {
            try {
                return redirect()->away($this->oauth->authorizationUrl($shop));
            } catch (ShopifyException $e) {
                return view('shopify.app', [
                    'urls' => $this->oauth->dashboardUrls(),
                    'oauthConfigured' => $this->oauth->isOAuthConfigured(),
                    'error' => $e->getMessage(),
                    'shop' => $shop,
                ]);
            }
        }

        if ($request->is('/')) {
            return redirect('/login');
        }

        return view('shopify.app', [
            'urls' => $this->oauth->dashboardUrls(),
            'oauthConfigured' => $this->oauth->isOAuthConfigured(),
            'error' => null,
            'shop' => '',
        ]);
    }

    /**
     * Manual install start.
     */
    public function install(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'shop' => ['required', 'string', 'max:255'],
        ]);

        try {
            return redirect()->away($this->oauth->authorizationUrl($validated['shop']));
        } catch (ShopifyException $e) {
            return redirect()
                ->route('shopify.app')
                ->with('error', $e->getMessage());
        }
    }

    /**
     * OAuth redirect / callback.
     */
    public function callback(Request $request): View|RedirectResponse
    {
        try {
            $result = $this->oauth->handleCallback($request);
            $adminAppUrl = $this->oauth->adminAppUrl($result['shop']);

            if ($adminAppUrl !== '') {
                return redirect()->away($adminAppUrl);
            }

            return view('shopify.installed', [
                'shop' => $result['shop'],
                'scope' => $result['scope'],
                'panelUrl' => '/dashboard',
            ]);
        } catch (ShopifyException $e) {
            return view('shopify.app', [
                'urls' => $this->oauth->dashboardUrls(),
                'oauthConfigured' => $this->oauth->isOAuthConfigured(),
                'error' => $e->getMessage(),
                'shop' => (string) $request->input('shop', ''),
            ]);
        }
    }

    /**
     * Tunnel health check.
     */
    public function health(): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'app' => config('app.name'),
            'urls' => $this->oauth->dashboardUrls(),
        ]);
    }

    public function customersDataRequest(Request $request): Response
    {
        return $this->acceptWebhook($request, 'customers/data_request');
    }

    public function customersRedact(Request $request): Response
    {
        return $this->acceptWebhook($request, 'customers/redact');
    }

    public function shopRedact(Request $request): Response
    {
        return $this->acceptWebhook($request, 'shop/redact');
    }

    private function acceptWebhook(Request $request, string $topic): Response
    {
        $hmac = $request->header('X-Shopify-Hmac-Sha256');
        if (! $this->oauth->verifyWebhook($request->getContent(), $hmac)) {
            Log::warning('Shopify webhook HMAC failed', ['topic' => $topic]);

            return response('Invalid HMAC', 401);
        }

        Log::info('Shopify compliance webhook received', [
            'topic' => $topic,
            'shop' => $request->header('X-Shopify-Shop-Domain'),
        ]);

        return response('OK', 200);
    }
}
