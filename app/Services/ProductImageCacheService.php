<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ShopifyProduct;
use App\Models\UyumSoftProduct;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Throwable;

class ProductImageCacheService
{
    public const MAX_EDGE = 1100;

    public const JPEG_QUALITY = 68;

    private const MAX_IMAGES = 24;

    /**
     * Download remote gallery/variant images once, then return local URLs for display.
     * Original Shopify URLs stay in the database so push/sync is unchanged.
     *
     * @return array{images: array<int, string>, variants: array<int, array<string, mixed>>}
     */
    public function localizeUyumSoft(UyumSoftProduct $product, bool $download = true): array
    {
        $directory = $this->uyumSoftDirectory($product);
        $images = $product->imageUrls();
        $variants = $product->variantRows();
        $urls = array_merge($images, array_column($variants, 'image') ?: []);

        if ($images === [] && $product->shopifyProduct) {
            foreach ($product->shopifyProduct->imageRows() as $row) {
                $src = trim((string) ($row['src'] ?? ''));
                if ($src !== '') {
                    $urls[] = $src;
                    $images[] = $src;
                }
            }
        }

        if ($download) {
            $this->ensure($directory, $urls);
        }

        return [
            'images' => $this->mapUrls($directory, $images),
            'variants' => $this->mapVariantImages($directory, $variants),
        ];
    }

    /**
     * @return array{images: array<int, string>, variants: array<int, array<string, mixed>>}
     */
    public function localizeShopify(ShopifyProduct $product, bool $download = true): array
    {
        $directory = $this->shopifyDirectory($product);
        $images = array_values(array_filter(array_map(
            static fn (array $image): string => trim((string) ($image['src'] ?? '')),
            $product->imageRows()
        )));
        $variants = $product->variantRows();
        $urls = array_merge($images, array_column($variants, 'image') ?: []);

        if ($download) {
            $this->ensure($directory, $urls);
        }

        return [
            'images' => $this->mapUrls($directory, $images),
            'variants' => $this->mapVariantImages($directory, $variants),
        ];
    }

    public function displayUrl(UyumSoftProduct|ShopifyProduct|string $target, ?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }

        $directory = match (true) {
            $target instanceof UyumSoftProduct => $this->uyumSoftDirectory($target),
            $target instanceof ShopifyProduct => $this->shopifyDirectory($target),
            default => (string) $target,
        };

        return $this->resolve($directory, $url);
    }

    /**
     * @param  array<int, mixed>  $urls
     */
    public function ensure(string $directory, array $urls): void
    {
        $seen = [];
        $count = 0;
        foreach ($urls as $url) {
            $url = trim((string) $url);
            if ($url === '' || isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            $this->cache($directory, $url);
            $count++;
            if ($count >= self::MAX_IMAGES) {
                break;
            }
        }
    }

    public function cache(string $directory, string $url): string
    {
        $url = trim($url);
        if ($url === '' || $this->isLocal($url)) {
            return $url;
        }

        if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
            return $url;
        }

        $relative = $this->relativePath($directory, $url);
        if (Storage::disk('public')->exists($relative)) {
            return $this->publicUrl($relative);
        }

        try {
            $response = Http::timeout(15)
                ->connectTimeout(5)
                ->withHeaders([
                    'Accept' => 'image/*,*/*;q=0.8',
                    'User-Agent' => 'EtiCart-ImageCache/1.0',
                ])
                ->get($url);

            if (! $response->successful() || $response->body() === '') {
                return $url;
            }

            $manager = new ImageManager(new Driver());
            $encoded = $manager->read($response->body())
                ->scaleDown(self::MAX_EDGE, self::MAX_EDGE)
                ->toJpeg(self::JPEG_QUALITY);

            Storage::disk('public')->put($relative, (string) $encoded);

            return $this->publicUrl($relative);
        } catch (Throwable) {
            return $url;
        }
    }

    public function uyumSoftDirectory(UyumSoftProduct $product): string
    {
        return 'products/'.$product->id.'/cache';
    }

    public function shopifyDirectory(ShopifyProduct $product): string
    {
        return 'shopify-products/'.$product->id.'/cache';
    }

    public function isLocal(string $url): bool
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? $url);

        return str_starts_with($url, '/storage/')
            || str_contains($path, '/storage/products/')
            || str_contains($path, '/storage/shopify-products/');
    }

    private function resolve(string $directory, string $url): string
    {
        if ($this->isLocal($url)) {
            return $url;
        }

        $relative = $this->relativePath($directory, $url);
        if (Storage::disk('public')->exists($relative)) {
            return $this->publicUrl($relative);
        }

        return $url;
    }

    /**
     * @param  array<int, string>  $urls
     * @return array<int, string>
     */
    private function mapUrls(string $directory, array $urls): array
    {
        $mapped = [];
        foreach ($urls as $url) {
            $url = trim((string) $url);
            if ($url === '') {
                continue;
            }
            $mapped[] = $this->resolve($directory, $url);
        }

        return array_values(array_unique($mapped));
    }

    /**
     * @param  array<int, array<string, mixed>>  $variants
     * @return array<int, array<string, mixed>>
     */
    private function mapVariantImages(string $directory, array $variants): array
    {
        foreach ($variants as $i => $variant) {
            $image = trim((string) ($variant['image'] ?? ''));
            if ($image === '') {
                continue;
            }
            $variants[$i]['image'] = $this->resolve($directory, $image);
        }

        return $variants;
    }

    private function relativePath(string $directory, string $url): string
    {
        return trim($directory, '/').'/'.$this->fingerprint($url).'.jpg';
    }

    private function fingerprint(string $url): string
    {
        $normalized = (string) preg_replace('/([?&])(width|height|crop)=\d+/i', '$1', $url);
        $normalized = (string) preg_replace('/\?&/', '?', $normalized);
        $normalized = (string) preg_replace('/[?&]$/', '', $normalized);

        return md5($normalized);
    }

    private function publicUrl(string $relative): string
    {
        return url(Storage::disk('public')->url($relative));
    }
}
