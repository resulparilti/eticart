<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopifyEmbeddedAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_root_still_redirects_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_shopify_app_url_post_does_not_expire_with_419(): void
    {
        $this->post('/shopify')->assertOk();
    }

    public function test_root_post_from_shopify_does_not_expire_with_419(): void
    {
        $this->post('/')->assertRedirect('/login');
    }

    public function test_https_tunnel_login_sets_samesite_none_session_cookie(): void
    {
        $response = $this->get('https://demo.trycloudflare.com/login');

        $response->assertOk();

        $cookie = $this->sessionCookie($response);

        $this->assertNotNull($cookie);
        $this->assertSame('none', strtolower((string) $cookie->getSameSite()));
        $this->assertTrue($cookie->isSecure());
    }

    public function test_installed_shop_opens_panel_inside_iframe_instead_of_oauth(): void
    {
        config([
            'services.shopify.access_token' => 'shpat_test',
            'services.shopify.store_url' => 'demo-shop.myshopify.com',
            'services.shopify.api_key' => 'key',
            'services.shopify.api_secret' => 'secret',
        ]);

        $this->get('/shopify?shop=demo-shop.myshopify.com')
            ->assertRedirect('/dashboard');
    }

    public function test_admin_app_url_points_to_shopify_embedded_admin(): void
    {
        config(['services.shopify.api_key' => 'abc123']);

        $url = app(\App\Services\ShopifyOAuthService::class)
            ->adminAppUrl('demo-shop.myshopify.com');

        $this->assertSame('https://admin.shopify.com/store/demo-shop/apps/abc123', $url);
    }

    public function test_local_login_keeps_samesite_lax_session_cookie(): void
    {
        $response = $this->get('/login');

        $response->assertOk();

        $cookie = $this->sessionCookie($response);

        $this->assertNotNull($cookie);
        $this->assertSame('lax', strtolower((string) $cookie->getSameSite()));
        $this->assertFalse($cookie->isSecure());
    }

    /**
     * @param  \Illuminate\Testing\TestResponse  $response
     */
    private function sessionCookie($response): ?\Symfony\Component\HttpFoundation\Cookie
    {
        $name = config('session.cookie');

        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $name) {
                return $cookie;
            }
        }

        return null;
    }
}
