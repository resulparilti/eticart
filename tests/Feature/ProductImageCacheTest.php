<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Models\UyumSoftProduct;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductImageCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_show_caches_shopify_images_locally_and_keeps_original_urls(): void
    {
        Storage::fake('public');
        $jpeg = $this->jpegBytes();
        $downloads = 0;

        Http::fake(function ($request) use (&$downloads, $jpeg) {
            if (str_contains($request->url(), 'cdn.shopify.com')) {
                $downloads++;

                return Http::response($jpeg, 200, ['Content-Type' => 'image/jpeg']);
            }

            return Http::response('', 404);
        });

        $user = User::factory()->create(['email_verified_at' => now()]);
        $product = $this->productWithRemoteImages();

        $this->actingAs($user)
            ->get(route('products.show', $product))
            ->assertOk()
            ->assertSee('/storage/products/'.$product->id.'/cache/', false)
            ->assertDontSee('https://cdn.shopify.com/ana.jpg', false)
            ->assertSee('eticart-lightbox', false);

        $product->refresh();
        $this->assertSame('https://cdn.shopify.com/ana.jpg', $product->imageUrls()[0]);
        $this->assertSame('https://cdn.shopify.com/varyant.jpg', $product->variant_info['variants'][0]['image']);
        $this->assertSame(2, $downloads);

        $firstDownloads = $downloads;
        $this->actingAs($user)->get(route('products.show', $product))->assertOk();
        $this->assertSame($firstDownloads, $downloads);
    }

    public function test_production_product_show_has_lightbox_and_variant_thumbs(): void
    {
        Storage::fake('public');
        Http::fake([
            'cdn.shopify.com/*' => Http::response($this->jpegBytes(), 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $this->seed(RolePermissionSeeder::class);
        $staff = User::factory()->create(['email_verified_at' => now()]);
        $staff->assignRole('production');

        $product = $this->productWithRemoteImages();

        $this->actingAs($staff)
            ->get(route('production.products.show', $product))
            ->assertOk()
            ->assertSee('eticart-lightbox', false)
            ->assertSee('openLightbox', false)
            ->assertSee('/storage/products/'.$product->id.'/cache/', false)
            ->assertSee('Siyah')
            ->assertSee('SKU-CACHE-S');
    }

    private function productWithRemoteImages(): UyumSoftProduct
    {
        return UyumSoftProduct::query()->create([
            'uyumsoft_id' => 'U-CACHE',
            'sku' => 'SKU-CACHE',
            'barcode' => '999',
            'title' => 'Cache Bere',
            'original_price' => 80,
            'stock' => 4,
            'is_active' => true,
            'images' => ['https://cdn.shopify.com/ana.jpg'],
            'variant_info' => [
                'variants' => [[
                    'title' => 'Siyah',
                    'sku' => 'SKU-CACHE-S',
                    'barcode' => '555',
                    'price' => 80,
                    'stock' => 4,
                    'image' => 'https://cdn.shopify.com/varyant.jpg',
                ]],
            ],
            'last_sync' => now(),
        ]);
    }

    private function jpegBytes(): string
    {
        $image = imagecreatetruecolor(80, 60);
        imagefilledrectangle($image, 0, 0, 79, 59, imagecolorallocate($image, 200, 80, 40));
        ob_start();
        imagejpeg($image, null, 90);
        imagedestroy($image);

        return (string) ob_get_clean();
    }
}
