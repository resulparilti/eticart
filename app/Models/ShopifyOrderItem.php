<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopifyOrderItem extends Model
{
    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'shopify_order_id',
        'shopify_line_item_id',
        'product_title',
        'variant_title',
        'sku',
        'quantity',
        'price',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'quantity' => 'integer',
        'price' => 'decimal:2',
    ];

    /**
     * Parent order.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(ShopifyOrder::class, 'shopify_order_id');
    }
}
