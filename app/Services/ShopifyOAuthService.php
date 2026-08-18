<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ShopifyException;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ShopifyOAuthService
{
    public const DEFAULT_SCOPES = 'read_products,write_products,read_inventory,write_inventory,read_locations,read_orders,write_orders,read_customers,read_fulfillments,write_fulfillments,read_merchant_managed_fulfillment_orders,write_merchant_managed_fulfillment_orders,read_shipping,write_shipping';

    /**
     * Public HTTPS base URL for Shopify (tunnel or production).
     */
    public function publicBaseUrl(): string
    {
        $configured = (string) (
            Setting::getValue('shopify_app_url')
            ?: config('services.shopify.app_url')
            ?: config('app.url')
        );

        $base = rtrim($configured, '/');

        if ($base === '') {
            $base = rtrim((string) url('/'), '/');
        }

        return $base;
    }

    /**
     * URLs to paste into Shopify Dev Dashboard.
     *
     * @return array{
     *     base: string,
     *     app_url: string,
     *     redirect_urls: array<int, string>,
     *     webhook_urls: array<string, string>,
     *     scopes: string,
     *     is_public_https: bool
     * }
     */
    public function dashboardUrls(): array
    {
        $base = $this->publicBaseUrl();

        return [
            'base' => $base,
            'app_url' => $base.'/shopify',
            'redirect_urls' => [
                $base.'/shopify/callback',
                $base.'/shopify/auth/callback',
            ],
            'webhook_urls' => [
                'customers/data_request' => $base.'/shopify/webhooks/customers-data-request',
                'customers/redact' => $base.'/shopify/webhooks/customers-redact',
                'shop/redact' => $base.'/shopify/webhooks/shop-redact',
            ],
            'scopes' => $this->scopes(),
            'is_public_https' => Str::startsWith($base, 'https://')
                && ! Str::contains($base, ['localhost', '127.0.0.1']),
        ];
    }

    public function clientId(): string
    {
        return (string) (Setting::getValue('shopify_api_key') ?: config('services.shopify.api_key'));
    }

    public function clientSecret(): string
    {
        return (string) (Setting::getValue('shopify_api_secret') ?: config('services.shopify.api_secret'));
    }

    public function scopes(): string
    {
        return (string) (Setting::getValue('shopify_scopes') ?: config('services.shopify.scopes') ?: self::DEFAULT_SCOPES);
    }

    public function isOAuthConfigured(): bool
    {
        return $this->clientId() !== '' && $this->clientSecret() !== '';
    }

    /**
     * Mağaza için access token kaydı var mı?
     */
    public function isInstalledForShop(?string $shop): bool
    {
        $token = (string) (Setting::getValue('shopify_access_token') ?: config('services.shopify.access_token', ''));
        if ($token === '') {
            return false;
        }

        if ($shop === null || trim($shop) === '') {
            return true;
        }

        $saved = (string) (Setting::getValue('shopify_store_url') ?: config('services.shopify.store_url', ''));
        if ($saved === '') {
            return true;
        }

        try {
            return $this->normalizeShop($shop) === $this->normalizeShop($saved);
        } catch (ShopifyException) {
            return true;
        }
    }

    /**
     * Shopify admin içinde uygulamayı iframe olarak açan adres.
     */
    public function adminAppUrl(string $shop): string
    {
        $apiKey = $this->clientId();
        if ($apiKey === '') {
            return '';
        }

        try {
            $normalized = $this->normalizeShop($shop);
        } catch (ShopifyException) {
            return '';
        }

        $handle = Str::before($normalized, '.myshopify.com');

        return 'https://admin.shopify.com/store/'.$handle.'/apps/'.$apiKey;
    }

    public function normalizeShop(?string $shop): string
    {
        $shop = strtolower(trim((string) $shop));
        $shop = preg_replace('#^https?://#', '', $shop) ?? $shop;
        $shop = rtrim($shop, '/');

        if ($shop !== '' && ! str_contains($shop, '.')) {
            $shop .= '.myshopify.com';
        }

        if ($shop === '' || ! preg_match('/^[a-z0-9][a-z0-9\-]*\.myshopify\.com$/', $shop)) {
            throw new ShopifyException('Geçerli bir mağaza adresi girin (ör. magaza.myshopify.com).');
        }

        return $shop;
    }

    public function authorizationUrl(string $shop): string
    {
        if (! $this->isOAuthConfigured()) {
            throw new ShopifyException('Shopify API Key / Secret tanımlı değil. Ayarlar → Shopify ekranına Client ID ve Secret girin.');
        }

        $shop = $this->normalizeShop($shop);
        $state = Str::random(40);
        Cache::put('shopify_oauth_state_'.$state, $shop, now()->addMinutes(15));

        $query = http_build_query([
            'client_id' => $this->clientId(),
            'scope' => $this->scopes(),
            'redirect_uri' => $this->dashboardUrls()['redirect_urls'][0],
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);

        return "https://{$shop}/admin/oauth/authorize?{$query}";
    }

    /**
     * @return array{shop: string, access_token: string, scope: string}
     */
    public function handleCallback(Request $request): array
    {
        $payload = $request->query();
        if (! isset($payload['hmac'])) {
            $payload = $request->except(['_token', '_method']);
        }

        $shop = $this->normalizeShop((string) ($payload['shop'] ?? ''));
        $code = (string) ($payload['code'] ?? '');
        $state = (string) ($payload['state'] ?? '');

        if ($code === '') {
            throw new ShopifyException('Shopify OAuth kodu eksik.');
        }

        $expectedShop = Cache::pull('shopify_oauth_state_'.$state);
        if (! is_string($expectedShop) || $expectedShop !== $shop) {
            throw new ShopifyException('OAuth state doğrulanamadı. Kurulumu yeniden başlatın.');
        }

        if (! $this->verifyRequestHmac($payload)) {
            throw new ShopifyException('Shopify HMAC doğrulaması başarısız.');
        }

        $response = Http::timeout(20)->acceptJson()->asJson()->post("https://{$shop}/admin/oauth/access_token", [
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'code' => $code,
        ]);

        if (! $response->successful()) {
            throw new ShopifyException('Access token alınamadı: '.$response->body());
        }

        $token = (string) $response->json('access_token');
        $scope = (string) $response->json('scope', '');

        if ($token === '') {
            throw new ShopifyException('Shopify access token boş döndü.');
        }

        Setting::setValue('shopify_store_url', $shop, 'shopify', 'Shopify Store URL');
        Setting::setValue('shopify_access_token', $token, 'shopify', 'Shopify Access Token');
        Cache::forget('eticart.settings');

        return [
            'shop' => $shop,
            'access_token' => $token,
            'scope' => $scope,
        ];
    }

    /**
     * @param  array<string, mixed>  $query
     */
    public function verifyRequestHmac(array $query): bool
    {
        $hmac = (string) ($query['hmac'] ?? '');
        if ($hmac === '' || $this->clientSecret() === '') {
            return false;
        }

        unset($query['hmac'], $query['signature']);
        ksort($query);

        $pairs = [];
        foreach ($query as $key => $value) {
            if (is_array($value)) {
                continue;
            }
            $pairs[] = $key.'='.$value;
        }

        $calculated = hash_hmac('sha256', implode('&', $pairs), $this->clientSecret());

        return hash_equals($calculated, $hmac);
    }

    public function verifyWebhook(?string $rawBody, ?string $hmacHeader): bool
    {
        if ($rawBody === null || $hmacHeader === null || $this->clientSecret() === '') {
            return false;
        }

        $calculated = base64_encode(hash_hmac('sha256', $rawBody, $this->clientSecret(), true));

        return hash_equals($calculated, $hmacHeader);
    }
}
