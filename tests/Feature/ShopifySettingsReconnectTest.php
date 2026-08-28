<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopifySettingsReconnectTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_token_explains_reconnect_instead_of_missing_scopes(): void
    {
        Setting::setValue('shopify_store_url', 'orenne-com.myshopify.com', 'shopify');
        Setting::setValue('shopify_api_key', 'key', 'shopify');
        Setting::setValue('shopify_api_secret', 'secret', 'shopify');
        Setting::setValue('shopify_access_token', '', 'shopify');

        $this->actingAs(User::factory()->create(['email_verified_at' => now()]))
            ->get('/settings/shopify')
            ->assertOk()
            ->assertSee('Access token kayıtlı değil', false)
            ->assertSee('Shopify’ı yeniden bağla', false)
            ->assertDontSee('Bu token’da eksik izinler', false);
    }

    public function test_reconnect_redirects_to_shopify_oauth(): void
    {
        Setting::setValue('shopify_store_url', 'orenne-com.myshopify.com', 'shopify');
        Setting::setValue('shopify_api_key', 'key', 'shopify');
        Setting::setValue('shopify_api_secret', 'secret', 'shopify');

        $response = $this->actingAs(User::factory()->create(['email_verified_at' => now()]))
            ->post('/settings/shopify/reconnect');

        $response->assertRedirect();
        $this->assertStringContainsString(
            'https://orenne-com.myshopify.com/admin/oauth/authorize',
            (string) $response->headers->get('Location')
        );
        $this->assertStringContainsString(
            'write_merchant_managed_fulfillment_orders',
            (string) $response->headers->get('Location')
        );
    }
}
