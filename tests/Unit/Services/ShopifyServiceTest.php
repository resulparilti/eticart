<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Exceptions\ShopifyException;
use App\Services\ShopifyService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopifyServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.shopify.store_url' => 'https://eticart-test.myshopify.com',
            'services.shopify.access_token' => 'shpat_test_token',
            'services.shopify.api_version' => '2024-01',
        ]);
    }

    public function test_is_configured_when_credentials_present(): void
    {
        $service = new ShopifyService();

        $this->assertTrue($service->isConfigured());
    }

    public function test_is_not_configured_without_token(): void
    {
        config(['services.shopify.access_token' => '']);

        $service = new ShopifyService();

        $this->assertFalse($service->isConfigured());
    }

    public function test_get_orders_returns_payload(): void
    {
        Http::fake([
            'eticart-test.myshopify.com/*' => Http::response([
                'orders' => [
                    ['id' => 1, 'name' => '#1001', 'total_price' => '10.00'],
                ],
            ], 200),
        ]);

        $service = new ShopifyService();
        $result = $service->getOrders(10);

        $this->assertCount(1, $result['orders']);
        $this->assertSame(1, $result['orders'][0]['id']);
    }

    public function test_missing_fulfillment_scopes_are_detected(): void
    {
        Http::fake([
            'eticart-test.myshopify.com/admin/oauth/access_scopes.json' => Http::response([
                'access_scopes' => [
                    ['handle' => 'write_orders'],
                    ['handle' => 'read_orders'],
                ],
            ], 200),
        ]);

        $service = new ShopifyService();
        $missing = $service->missingFulfillmentScopes();

        $this->assertContains('write_merchant_managed_fulfillment_orders', $missing);
        $this->assertNotContains('write_fulfillments', $missing);

        $this->expectException(ShopifyException::class);
        $this->expectExceptionMessage('write_merchant_managed_fulfillment_orders');
        $service->assertCanFulfillOrders();
    }

    public function test_merchant_managed_scope_is_enough_without_write_fulfillments(): void
    {
        Http::fake([
            'eticart-test.myshopify.com/admin/oauth/access_scopes.json' => Http::response([
                'access_scopes' => [
                    ['handle' => 'write_orders'],
                    ['handle' => 'write_merchant_managed_fulfillment_orders'],
                    ['handle' => 'read_merchant_managed_fulfillment_orders'],
                ],
            ], 200),
        ]);

        $service = new ShopifyService();

        $this->assertSame([], $service->missingFulfillmentScopes());
        $service->assertCanFulfillOrders();
        $this->assertTrue(true);
    }

    public function test_throws_when_not_configured(): void
    {
        config([
            'services.shopify.store_url' => '',
            'services.shopify.access_token' => '',
        ]);

        $this->expectException(ShopifyException::class);

        (new ShopifyService())->getOrders();
    }
}
