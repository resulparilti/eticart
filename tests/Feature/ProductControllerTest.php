<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ShopifyProduct;
use App\Models\User;
use App\Models\UyumSoftProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProductControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_products_index_requires_auth(): void
    {
        $this->get(route('products.index'))->assertRedirect('/login');
    }

    public function test_products_index_renders(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        UyumSoftProduct::query()->create([
            'uyumsoft_id' => 'U-1',
            'sku' => 'SKU-1',
            'title' => 'Test Ürün',
            'original_price' => 50,
            'stock' => 3,
            'last_sync' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('products.index'))
            ->assertOk();
    }

    public function test_pull_shopify_products_imports_grouped_product_rows(): void
    {
        config([
            'services.shopify.store_url' => 'https://eticart-test.myshopify.com',
            'services.shopify.access_token' => 'shpat_test_token',
            'services.shopify.api_version' => '2024-01',
        ]);

        UyumSoftProduct::query()->create([
            'uyumsoft_id' => 'U-100',
            'sku' => 'SKU-100',
            'title' => 'Uyum Ürün',
            'original_price' => 99,
            'stock' => 5,
            'last_sync' => now(),
        ]);

        Http::fake([
            'eticart-test.myshopify.com/*' => Http::response([
                'products' => [
                    [
                        'id' => 9001,
                        'title' => 'Shopify Ürün',
                        'body_html' => '<p>Test açıklama</p>',
                        'status' => 'active',
                        'handle' => 'shopify-urun',
                        'images' => [
                            ['src' => 'https://cdn.shopify.com/image-1.jpg', 'alt' => 'Görsel 1', 'position' => 1],
                        ],
                        'variants' => [
                            [
                                'id' => 8001,
                                'title' => 'Small',
                                'sku' => 'SKU-100',
                                'price' => '120.00',
                                'inventory_quantity' => 7,
                                'inventory_item_id' => 7001,
                            ],
                            [
                                'id' => 8002,
                                'title' => 'Large',
                                'sku' => 'SKU-100-L',
                                'price' => '140.00',
                                'inventory_quantity' => 3,
                                'inventory_item_id' => 7002,
                            ],
                        ],
                    ],
                ],
            ], 200, ['Link' => '']),
        ]);

        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)
            ->post(route('products.pull-shopify'))
            ->assertRedirect(route('products.index', ['tab' => 'synced']))
            ->assertSessionHas('success');

        $this->assertSame(1, ShopifyProduct::query()->count());

        $mirror = ShopifyProduct::query()->where('shopify_product_id', '9001')->first();
        $this->assertNotNull($mirror);
        $this->assertSame('Shopify Ürün', $mirror->title);
        $this->assertSame(2, $mirror->variant_count);
        $this->assertSame(10, $mirror->stock);
        $this->assertCount(2, $mirror->variantRows());
        $this->assertNotNull($mirror->uyumsoft_product_id);

        $this->actingAs($user)
            ->get(route('products.shopify-mirror.show', $mirror))
            ->assertOk()
            ->assertSee('Shopify Ürün')
            ->assertSee('Varyantlar')
            ->assertSee('SKU-100')
            ->assertSee('SKU-100-L')
            ->assertSee('Small')
            ->assertSee('Large');
    }
}
