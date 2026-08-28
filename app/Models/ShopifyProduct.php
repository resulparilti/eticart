<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ShopifyProduct extends Model
{
    use SoftDeletes;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'shopify_product_id',
        'shopify_variant_id',
        'inventory_item_id',
        'title',
        'description',
        'images',
        'metafields',
        'collections',
        'variants',
        'status',
        'handle',
        'sku',
        'price',
        'price_max',
        'stock',
        'variant_count',
        'uyumsoft_product_id',
        'last_sync',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'images' => 'array',
        'metafields' => 'array',
        'collections' => 'array',
        'variants' => 'array',
        'price' => 'decimal:2',
        'price_max' => 'decimal:2',
        'stock' => 'integer',
        'variant_count' => 'integer',
        'last_sync' => 'datetime',
    ];

    /**
     * Linked UyumSoft product.
     */
    public function uyumSoftProduct(): BelongsTo
    {
        return $this->belongsTo(UyumSoftProduct::class, 'uyumsoft_product_id');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function variantRows(): array
    {
        $variants = $this->variants ?? [];
        if (! is_array($variants) || $variants === []) {
            return [[
                'id' => $this->shopify_variant_id,
                'title' => 'Varsayılan',
                'sku' => $this->sku,
                'price' => (float) $this->price,
                'stock' => (int) $this->stock,
                'inventory_item_id' => $this->inventory_item_id,
            ]];
        }

        return array_values(array_map(static function (array $variant): array {
            return [
                'id' => (string) ($variant['id'] ?? ''),
                'title' => (string) ($variant['title'] ?? 'Varyant'),
                'sku' => $variant['sku'] ?? null,
                'price' => (float) ($variant['price'] ?? 0),
                'compare_at_price' => isset($variant['compare_at_price']) ? (float) $variant['compare_at_price'] : null,
                'stock' => (int) ($variant['inventory_quantity'] ?? $variant['stock'] ?? 0),
                'barcode' => $variant['barcode'] ?? null,
                'inventory_item_id' => $variant['inventory_item_id'] ?? null,
                'image' => $variant['image'] ?? $variant['image_url'] ?? null,
            ];
        }, $variants));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function imageRows(): array
    {
        $images = $this->images ?? [];
        if (! is_array($images)) {
            return [];
        }

        return array_values(array_map(static function ($image): array {
            if (is_string($image)) {
                return ['src' => $image, 'alt' => null];
            }
            if (! is_array($image)) {
                return ['src' => '', 'alt' => null];
            }

            return [
                'src' => (string) ($image['src'] ?? $image['url'] ?? ''),
                'alt' => $image['alt'] ?? null,
            ];
        }, $images));
    }

    public function priceLabel(): string
    {
        $min = (float) $this->price;
        $max = (float) ($this->price_max ?? $this->price);

        if ($max > $min) {
            return '₺'.number_format($min, 2).' – ₺'.number_format($max, 2);
        }

        return '₺'.number_format($min, 2);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'active' => 'Yayında',
            'draft' => 'Taslak',
            'archived' => 'Arşiv',
            default => $this->status ?: 'Bilinmiyor',
        };
    }
}
