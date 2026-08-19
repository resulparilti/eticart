<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProcessBulkProductAction;
use App\Models\ShopifyProduct;
use App\Models\User;
use App\Models\UyumSoftProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
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

    public function test_products_index_shows_thumbnails_and_honors_per_page(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        UyumSoftProduct::query()->create([
            'uyumsoft_id' => 'U-IMG',
            'sku' => 'SKU-IMG',
            'title' => 'Görselli Ürün',
            'original_price' => 50,
            'stock' => 3,
            'images' => [
                ['src' => 'https://cdn.shopify.com/liste.jpg', 'position' => 1],
            ],
            'last_sync' => now(),
        ]);

        $withoutImage = UyumSoftProduct::query()->create([
            'uyumsoft_id' => 'U-NOIMG',
            'sku' => 'SKU-NOIMG',
            'title' => 'Görselsiz Ürün',
            'original_price' => 20,
            'stock' => 1,
            'last_sync' => now(),
        ]);

        UyumSoftProduct::query()->create([
            'uyumsoft_id' => 'U-EMPTY',
            'sku' => 'SKU-EMPTY',
            'title' => 'İkonsuz Ürün',
            'original_price' => 15,
            'stock' => 1,
            'last_sync' => now(),
        ]);

        ShopifyProduct::query()->create([
            'shopify_product_id' => '88001',
            'title' => 'Ayna Ürün',
            'sku' => 'SKU-MIRROR',
            'images' => [
                ['src' => 'https://cdn.shopify.com/mirror.jpg', 'position' => 1],
            ],
            'uyumsoft_product_id' => $withoutImage->id,
            'last_sync' => now(),
        ]);

        $listed = $this->actingAs($user)
            ->get(route('products.index'))
            ->assertOk()
            ->assertSee('https://cdn.shopify.com/liste.jpg?width=96', false)
            ->assertSee('https://cdn.shopify.com/mirror.jpg?width=96', false)
            ->assertSee('eticart-product-thumb--empty', false)
            ->assertSee('bi-image', false)
            ->assertSee('name="per_page"', false)
            ->assertSee('id="productPagerTop"', false)
            ->assertSee('id="productPagerBottom"', false)
            ->assertSee('value="100"', false);

        for ($i = 0; $i < 12; $i++) {
            UyumSoftProduct::query()->create([
                'uyumsoft_id' => 'U-PAGE-'.$i,
                'sku' => 'SKU-PAGE-'.$i,
                'title' => 'Sayfalı Ürün '.$i,
                'original_price' => 10,
                'stock' => 1,
                'last_sync' => now(),
            ]);
        }

        $paged = $this->actingAs($user)
            ->get(route('products.index', ['per_page' => 10]))
            ->assertOk();

        $this->assertSame(10, substr_count($paged->getContent(), 'class="form-check-input product-check"'));
        $paged->assertSee('page=2', false);

        $invalid = $this->actingAs($user)
            ->get(route('products.index', ['per_page' => 15]))
            ->assertOk();
        $this->assertSame(15, substr_count($invalid->getContent(), 'class="form-check-input product-check"'));
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

    public function test_product_edit_can_save_variant_image_without_shopify(): void
    {
        Storage::fake('public');
        $user = User::factory()->create(['email_verified_at' => now()]);
        $product = UyumSoftProduct::query()->create([
            'uyumsoft_id' => 'U-IMG',
            'sku' => 'SKU-IMG',
            'title' => 'Bere',
            'original_price' => 100,
            'stock' => 2,
            'is_active' => true,
            'last_sync' => now(),
            'variant_info' => [
                'attributes' => [['name' => 'RENK', 'values' => ['Siyah']]],
                'variants' => [[
                    'title' => 'Siyah',
                    'sku' => 'SKU-IMG-S',
                    'barcode' => '111',
                    'price' => 100,
                    'stock' => 2,
                ]],
            ],
        ]);

        $this->actingAs($user)
            ->get(route('products.edit', $product))
            ->assertOk()
            ->assertSee('Varyant görselleri')
            ->assertSee('Siyah');

        $this->actingAs($user)
            ->put(route('products.update', $product), [
                'title' => 'Bere',
                'sku' => 'SKU-IMG',
                'original_price' => 100,
                'stock' => 2,
                'is_active' => 1,
                'variant_image_files' => [
                    0 => UploadedFile::fake()->image('siyah.jpg', 40, 40),
                ],
            ])
            ->assertRedirect(route('products.show', $product));

        $product->refresh();
        $this->assertNotEmpty($product->variant_info['variants'][0]['image']);
        $this->assertStringContainsString('/storage/products/'.$product->id.'/variants/', $product->variant_info['variants'][0]['image']);
    }

    public function test_pull_shopify_one_imports_images_metafields_and_collections(): void
    {
        config([
            'services.shopify.store_url' => 'https://eticart-test.myshopify.com',
            'services.shopify.access_token' => 'shpat_test_token',
            'services.shopify.api_version' => '2024-01',
        ]);

        $product = UyumSoftProduct::query()->create([
            'uyumsoft_id' => 'U-PULL',
            'sku' => 'SKU-PULL',
            'title' => 'Bere',
            'original_price' => 80,
            'stock' => 4,
            'is_active' => true,
            'shopify_id' => '9100',
            'synced_to_shopify' => true,
            'last_sync' => now(),
            'variant_info' => [
                'variants' => [[
                    'title' => 'Siyah',
                    'sku' => 'SKU-PULL-S',
                    'barcode' => '555',
                    'price' => 80,
                    'stock' => 4,
                ]],
            ],
        ]);

        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, '/products/9100/metafields.json')) {
                return Http::response([
                    'metafields' => [
                        [
                            'id' => 1,
                            'namespace' => 'custom',
                            'key' => 'kumas',
                            'value' => 'Pamuk',
                            'type' => 'single_line_text_field',
                        ],
                        [
                            'id' => 2,
                            'namespace' => 'custom',
                            'key' => 'kombin_urunler',
                            'value' => '["gid://shopify/Product/9299859570908","gid://shopify/Product/9299859701980"]',
                            'type' => 'list.product_reference',
                        ],
                        [
                            'id' => 3,
                            'namespace' => 'custom',
                            'key' => 'buy_together_product',
                            'value' => 'gid://shopify/Product/9299859570908',
                            'type' => 'product_reference',
                        ],
                        [
                            'id' => 4,
                            'namespace' => 'custom',
                            'key' => 'bakim_talimati',
                            'value' => json_encode([
                                'type' => 'root',
                                'children' => [
                                    ['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => 'Akrilik iplik pratiktir.']]],
                                    [
                                        'type' => 'list',
                                        'listType' => 'unordered',
                                        'children' => [
                                            ['type' => 'list-item', 'children' => [
                                                ['type' => 'text', 'value' => 'Makinede '],
                                                ['type' => 'text', 'value' => '30°C', 'bold' => true],
                                                ['type' => 'text', 'value' => '’de yıkayın.'],
                                            ]],
                                        ],
                                    ],
                                ],
                            ], JSON_UNESCAPED_UNICODE),
                            'type' => 'rich_text_field',
                        ],
                    ],
                ], 200);
            }

            if (str_contains($url, 'custom_collections.json')) {
                return Http::response([
                    'custom_collections' => [[
                        'id' => 44,
                        'title' => 'Kış',
                        'handle' => 'kis',
                    ]],
                ], 200);
            }

            if (str_contains($url, 'smart_collections.json')) {
                return Http::response(['smart_collections' => []], 200);
            }

            if (str_contains($url, '/products/9100.json')) {
                return Http::response([
                    'product' => [
                        'id' => 9100,
                        'title' => 'Bere Shopify',
                        'body_html' => '<p>Shopify</p>',
                        'status' => 'active',
                        'handle' => 'bere',
                        'images' => [
                            [
                                'id' => 77,
                                'src' => 'https://cdn.shopify.com/bere.jpg',
                                'alt' => 'Bere',
                                'position' => 1,
                                'variant_ids' => [8100],
                            ],
                            [
                                'id' => 78,
                                'src' => 'https://cdn.shopify.com/bere-2.jpg',
                                'alt' => 'Bere 2',
                                'position' => 2,
                            ],
                            [
                                'id' => 79,
                                'src' => 'https://cdn.shopify.com/bere-3.jpg',
                                'alt' => 'Bere 3',
                                'position' => 3,
                            ],
                        ],
                        'variants' => [[
                            'id' => 8100,
                            'title' => 'Siyah',
                            'sku' => 'SKU-PULL-S',
                            'barcode' => '555',
                            'price' => '90.00',
                            'inventory_quantity' => 4,
                            'inventory_item_id' => 7100,
                            'image_id' => 77,
                        ]],
                    ],
                ], 200);
            }

            return Http::response(['products' => []], 200);
        });

        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)
            ->post(route('products.pull-shopify-one', $product))
            ->assertRedirect(route('products.show', $product))
            ->assertSessionHas('success');

        $product->refresh();
        $this->assertContains('https://cdn.shopify.com/bere.jpg', $product->imageUrls());
        $this->assertSame('https://cdn.shopify.com/bere.jpg', $product->variant_info['variants'][0]['image']);

        $mirror = ShopifyProduct::query()->where('shopify_product_id', '9100')->first();
        $this->assertNotNull($mirror);
        $this->assertSame('Pamuk', $mirror->metafields[0]['value'] ?? null);
        $this->assertSame('Kış', $mirror->collections[0]['title'] ?? null);

        $this->actingAs($user)
            ->get(route('products.show', $product))
            ->assertOk()
            ->assertSee('data-full-url="https://cdn.shopify.com/bere.jpg"', false)
            ->assertSee('Diğer görselleri gör', false)
            ->assertSee('Shopify koleksiyonları')
            ->assertSee('Kış')
            ->assertSee('Shopify meta alanları')
            ->assertSee('Kumas')
            ->assertSee('Pamuk')
            ->assertSee('Kombin ürünler')
            ->assertSee('Birlikte al')
            ->assertSee('Bakım talimatı')
            ->assertSee('30°C', false);
    }

    public function test_pull_shopify_clears_images_deleted_on_shopify(): void
    {
        config([
            'services.shopify.store_url' => 'https://eticart-test.myshopify.com',
            'services.shopify.access_token' => 'shpat_test_token',
            'services.shopify.api_version' => '2024-01',
        ]);

        $product = UyumSoftProduct::query()->create([
            'uyumsoft_id' => '20ELU009',
            'sku' => '20ELU009',
            'title' => 'Silinmiş görselli ürün',
            'original_price' => 80,
            'stock' => 2,
            'is_active' => true,
            'shopify_id' => '9300',
            'synced_to_shopify' => true,
            'last_sync' => now(),
            'images' => [
                'https://cdn.shopify.com/old-1.jpg',
                'https://cdn.shopify.com/old-2.jpg',
            ],
            'variant_info' => [
                'variants' => [[
                    'title' => 'Standart',
                    'sku' => '20ELU009',
                    'barcode' => '999',
                    'price' => 80,
                    'stock' => 2,
                    'image' => 'https://cdn.shopify.com/old-1.jpg',
                ]],
            ],
        ]);

        Http::fake([
            'eticart-test.myshopify.com/admin/api/2024-01/products/9300.json' => Http::response([
                'product' => [
                    'id' => 9300,
                    'title' => 'Silinmiş görselli ürün',
                    'status' => 'active',
                    'images' => [],
                    'variants' => [[
                        'id' => 1,
                        'title' => 'Standart',
                        'sku' => '20ELU009',
                        'barcode' => '999',
                        'price' => '80.00',
                        'inventory_quantity' => 2,
                    ]],
                ],
            ], 200),
            'eticart-test.myshopify.com/admin/api/2024-01/products/9300/metafields.json' => Http::response(['metafields' => []], 200),
            'eticart-test.myshopify.com/admin/api/2024-01/custom_collections.json*' => Http::response(['custom_collections' => []], 200),
            'eticart-test.myshopify.com/admin/api/2024-01/smart_collections.json*' => Http::response(['smart_collections' => []], 200),
        ]);

        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)
            ->post(route('products.pull-shopify-one', $product))
            ->assertRedirect(route('products.show', $product))
            ->assertSessionHas('success');

        $product->refresh();
        $this->assertSame([], $product->imageUrls());
        $this->assertEmpty($product->variant_info['variants'][0]['image'] ?? null);

        $mirror = ShopifyProduct::query()->where('shopify_product_id', '9300')->first();
        $this->assertNotNull($mirror);
        $this->assertSame([], $mirror->imageRows());
    }

    public function test_bulk_pull_shopify_uses_selected_products(): void
    {
        config([
            'services.shopify.store_url' => 'https://eticart-test.myshopify.com',
            'services.shopify.access_token' => 'shpat_test_token',
            'services.shopify.api_version' => '2024-01',
        ]);

        $product = UyumSoftProduct::query()->create([
            'uyumsoft_id' => 'U-BULK',
            'sku' => 'SKU-BULK',
            'title' => 'Atkı',
            'original_price' => 60,
            'stock' => 1,
            'shopify_id' => '9200',
            'synced_to_shopify' => true,
            'last_sync' => now(),
        ]);

        Http::fake([
            'eticart-test.myshopify.com/admin/api/2024-01/products/9200.json' => Http::response([
                'product' => [
                    'id' => 9200,
                    'title' => 'Atkı',
                    'status' => 'active',
                    'images' => [['id' => 1, 'src' => 'https://cdn.shopify.com/atki.jpg', 'position' => 1]],
                    'variants' => [['id' => 1, 'sku' => 'SKU-BULK', 'price' => '60.00', 'inventory_quantity' => 1]],
                ],
            ], 200),
            'eticart-test.myshopify.com/admin/api/2024-01/products/9200/metafields.json' => Http::response(['metafields' => []], 200),
            'eticart-test.myshopify.com/admin/api/2024-01/custom_collections.json*' => Http::response(['custom_collections' => []], 200),
            'eticart-test.myshopify.com/admin/api/2024-01/smart_collections.json*' => Http::response(['smart_collections' => []], 200),
        ]);

        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)
            ->post(route('products.bulk'), [
                'action' => 'pull_shopify',
                'product_ids' => [$product->id],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');
    }

    public function test_bulk_actions_are_dispatched_to_queue(): void
    {
        Queue::fake();

        $user = User::factory()->create(['email_verified_at' => now()]);
        $product = UyumSoftProduct::query()->create([
            'uyumsoft_id' => 'U-QUEUE',
            'sku' => 'SKU-QUEUE',
            'title' => 'Kuyruk Ürün',
            'original_price' => 40,
            'stock' => 2,
            'is_active' => false,
            'last_sync' => now(),
        ]);

        $this->actingAs($user)
            ->post(route('products.bulk'), [
                'action' => 'push_shopify',
                'product_ids' => [$product->id],
                'sync_options' => ['all'],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        Queue::assertPushed(ProcessBulkProductAction::class, function (ProcessBulkProductAction $job) use ($product): bool {
            return $job->action === 'push_shopify'
                && $job->productIds === [$product->id];
        });

        $this->actingAs($user)
            ->post(route('products.bulk'), [
                'action' => 'activate',
                'product_ids' => [$product->id],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertTrue((bool) $product->fresh()?->is_active);
        Queue::assertPushed(ProcessBulkProductAction::class, function (ProcessBulkProductAction $job): bool {
            return $job->action === 'activate';
        });
    }
}
