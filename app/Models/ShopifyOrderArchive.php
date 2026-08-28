<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShopifyOrderArchive extends Model
{
    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'local_order_id',
        'shopify_order_id',
        'order_number',
        'customer_name',
        'customer_email',
        'customer_phone',
        'total_price',
        'currency',
        'payment_status',
        'fulfillment_status',
        'reason',
        'snapshot',
        'archived_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'snapshot' => 'array',
        'total_price' => 'decimal:2',
        'archived_at' => 'datetime',
    ];

    public function reasonLabel(): string
    {
        return match ($this->reason) {
            'shopify_deleted' => 'Shopify’dan silindi',
            'shopify_not_found_on_push' => 'Shopify kaydı bulunamadı (404)',
            'shopify_not_found' => 'Shopify kaydı bulunamadı',
            default => $this->reason ?: 'Arşivlendi',
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function snapshotItems(): array
    {
        $items = $this->snapshot['items'] ?? [];

        return is_array($items) ? $items : [];
    }
}
