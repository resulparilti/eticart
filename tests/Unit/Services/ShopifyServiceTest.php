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

    public function test_retries_409_conflict_then_succeeds(): void
    {
        Http::fake([
            'eticart-test.myshopify.com/*' => Http::sequence()
                ->push([
                    'errors' => [
                        'product' => ['This product is currently being modified. Please try again later.'],
                    ],
                ], 409)
                ->push([
                    'orders' => [
                        ['id' => 9, 'name' => '#1009'],
                    ],
                ], 200),
        ]);

        $result = (new ShopifyService())->getOrders(10);

        $this->assertCount(1, $result['orders']);
        $this->assertSame(9, $result['orders'][0]['id']);
    }

    public function test_update_inventory_resolves_location_connects_then_sets_quantity(): void
    {
        Http::fake([
            'eticart-test.myshopify.com/admin/api/2024-01/locations.json' => Http::response([
                'locations' => [
                    ['id' => 111, 'name' => 'Shop', 'active' => true, 'primary' => true],
                ],
            ], 200),
            'eticart-test.myshopify.com/admin/api/2024-01/inventory_items/9001.json' => Http::response([
                'inventory_item' => ['id' => 9001, 'tracked' => true],
            ], 200),
            'eticart-test.myshopify.com/admin/api/2024-01/inventory_levels/set.json' => Http::response([
                'inventory_level' => ['available' => 8, 'inventory_item_id' => 9001, 'location_id' => 111],
            ], 200),
        ]);

        $result = (new ShopifyService())->updateInventory(9001, 8);

        $this->assertSame(8, $result['inventory_level']['available']);
        Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
            return $request->method() === 'POST'
                && str_contains($request->url(), 'inventory_levels/set.json')
                && (int) ($request->data()['available'] ?? 0) === 8
                && (int) ($request->data()['location_id'] ?? 0) === 111;
        });
    }

    public function test_update_inventory_connects_when_item_is_not_stocked_at_location(): void
    {
        Http::fake([
            'eticart-test.myshopify.com/admin/api/2024-01/locations.json' => Http::response([
                'locations' => [
                    ['id' => 222, 'name' => 'Shop', 'active' => true, 'primary' => true],
                ],
            ], 200),
            'eticart-test.myshopify.com/admin/api/2024-01/inventory_items/9002.json' => Http::response([
                'inventory_item' => ['id' => 9002, 'tracked' => true],
            ], 200),
            'eticart-test.myshopify.com/admin/api/2024-01/inventory_levels/set.json' => Http::sequence()
                ->push(['errors' => 'Not stocked at the location.'], 422)
                ->push(['inventory_level' => ['available' => 4]], 200),
            'eticart-test.myshopify.com/admin/api/2024-01/inventory_levels/connect.json' => Http::response([
                'inventory_level' => ['inventory_item_id' => 9002, 'location_id' => 222],
            ], 201),
        ]);

        $result = (new ShopifyService())->updateInventory(9002, 4);

        $this->assertSame(4, $result['inventory_level']['available']);
        Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
            return $request->method() === 'POST'
                && str_contains($request->url(), 'inventory_levels/connect.json')
                && empty($request->data()['relocate_if_necessary']);
        });
    }

    public function test_update_inventory_uses_existing_item_location_when_settings_empty(): void
    {
        Http::fake([
            'eticart-test.myshopify.com/admin/api/2024-01/locations.json' => Http::response([
                'locations' => [],
            ], 200),
            'eticart-test.myshopify.com/admin/api/2024-01/inventory_levels.json*' => Http::response([
                'inventory_levels' => [
                    ['inventory_item_id' => 9003, 'location_id' => 777, 'available' => 10],
                ],
            ], 200),
            'eticart-test.myshopify.com/admin/api/2024-01/inventory_items/9003.json' => Http::response([
                'inventory_item' => ['id' => 9003, 'tracked' => true],
            ], 200),
            'eticart-test.myshopify.com/admin/api/2024-01/inventory_levels/set.json' => Http::response([
                'inventory_level' => ['available' => 9, 'location_id' => 777],
            ], 200),
        ]);

        $result = (new ShopifyService())->updateInventory(9003, 9);

        $this->assertSame(9, $result['inventory_level']['available']);
        Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
            return $request->method() === 'POST'
                && str_contains($request->url(), 'inventory_levels/set.json')
                && (int) ($request->data()['location_id'] ?? 0) === 777;
        });
    }
}
