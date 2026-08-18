<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class UyumSoftProduct extends Model
{
    use SoftDeletes;

    /**
     * @var string
     */
    protected $table = 'uyumsoft_products';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'uyumsoft_id',
        'sku',
        'barcode',
        'title',
        'description',
        'variant_info',
        'images',
        'original_price',
        'stock',
        'synced_to_shopify',
        'is_active',
        'shopify_id',
        'last_sync',
        'source_hash',
        'shopify_synced_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'variant_info' => 'array',
        'images' => 'array',
        'original_price' => 'decimal:2',
        'stock' => 'integer',
        'synced_to_shopify' => 'boolean',
        'is_active' => 'boolean',
        'last_sync' => 'datetime',
        'shopify_synced_at' => 'datetime',
    ];

    /**
     * Linked Shopify product.
     */
    public function shopifyProduct(): HasOne
    {
        return $this->hasOne(ShopifyProduct::class, 'uyumsoft_product_id');
    }

    /**
     * Normalized variant rows for UI.
     *
     * @return array<int, array<string, mixed>>
     */
    public function variantRows(): array
    {
        $info = $this->variant_info;

        if (! is_array($info) || $info === []) {
            return [[
                'sku' => $this->sku,
                'barcode' => $this->barcode,
                'title' => 'Varsayılan',
                'price' => $this->original_price,
                'stock' => $this->stock,
            ]];
        }

        $candidates = $info['variants'] ?? $info['Items'] ?? $info['items'] ?? null;

        if (! is_array($candidates) || ! array_is_list($candidates) || $candidates === []) {
            if (isset($info['sku']) || isset($info['price']) || isset($info['stock'])) {
                return [[
                    'sku' => $info['sku'] ?? $this->sku,
                    'barcode' => $info['barcode'] ?? $info['barkod'] ?? $this->barcode,
                    'title' => $info['title'] ?? $info['name'] ?? 'Varsayılan',
                    'price' => $info['price'] ?? $info['salePrice'] ?? $this->original_price,
                    'stock' => $info['stock'] ?? $info['quantity'] ?? $this->stock,
                ]];
            }

            return [[
                'sku' => $this->sku,
                'barcode' => $this->barcode,
                'title' => 'Varsayılan',
                'price' => $this->original_price,
                'stock' => $this->stock,
            ]];
        }

        $rows = [];
        foreach ($candidates as $variant) {
            if (! is_array($variant)) {
                continue;
            }
            $rows[] = [
                'sku' => $variant['sku'] ?? $variant['stockCode'] ?? $variant['code'] ?? null,
                'barcode' => $variant['barcode'] ?? $variant['barkod'] ?? null,
                'title' => $variant['title'] ?? $variant['name'] ?? $variant['variant_title'] ?? 'Varyant',
                'price' => $variant['price'] ?? $variant['salePrice'] ?? $variant['fiyat'] ?? $this->original_price,
                'stock' => $variant['stock'] ?? $variant['quantity'] ?? $variant['qty'] ?? '-',
                'attribute_1' => $variant['attribute_1'] ?? null,
                'attribute_2' => $variant['attribute_2'] ?? null,
                'attribute_3' => $variant['attribute_3'] ?? null,
            ];
        }

        return $rows !== [] ? $rows : [[
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'title' => 'Varsayılan',
            'price' => $this->original_price,
            'stock' => $this->stock,
        ]];
    }

    /**
     * Attribute option groups (e.g. RENK, BEDEN).
     *
     * @return array<int, array{name: string, values: array<int, string>}>
     */
    public function attributeGroups(): array
    {
        $info = $this->variant_info;
        if (! is_array($info)) {
            return [];
        }

        $attributes = $info['attributes'] ?? [];
        if (! is_array($attributes)) {
            return [];
        }

        $groups = [];
        foreach ($attributes as $attribute) {
            if (! is_array($attribute)) {
                continue;
            }
            $name = (string) ($attribute['name'] ?? '');
            $values = $attribute['values'] ?? [];
            if ($name === '' || ! is_array($values) || $values === []) {
                continue;
            }
            $groups[] = [
                'name' => $name,
                'values' => array_values(array_map('strval', $values)),
            ];
        }

        return $groups;
    }

    /**
     * @return array<int, string>
     */
    public function imageUrls(): array
    {
        $images = $this->images ?? [];
        if (! is_array($images)) {
            return [];
        }

        return array_values(array_filter(array_map(static function ($item) {
            if (is_string($item)) {
                return $item;
            }
            if (is_array($item)) {
                return $item['src'] ?? $item['url'] ?? $item['image'] ?? null;
            }

            return null;
        }, $images)));
    }
}
