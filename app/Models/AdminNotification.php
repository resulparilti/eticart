<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AdminNotification extends Model
{
    public const TYPE_ORDER_CREATED = 'order_created';

    public const TYPE_ORDER_CANCELLED = 'order_cancelled';

    public const TYPE_ORDER_PREPARING = 'order_preparing';

    public const TYPE_ORDER_SHIPPED = 'order_shipped';

    public const TYPE_ORDER_DELIVERED = 'order_delivered';

    public const TYPE_CUSTOMER_CREATED = 'customer_created';

    public const TYPE_PRODUCT_CREATED = 'product_created';

    public const TYPE_PRODUCT_UPDATED = 'product_updated';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'type',
        'title',
        'message',
        'url',
        'subject_type',
        'subject_id',
        'read_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'read_at' => 'datetime',
    ];

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function isUnread(): bool
    {
        return $this->read_at === null;
    }

    /**
     * @return array<string, string>
     */
    public static function typeLabels(): array
    {
        return [
            self::TYPE_ORDER_CREATED => 'Yeni sipariş',
            self::TYPE_ORDER_CANCELLED => 'Sipariş iptali',
            self::TYPE_ORDER_PREPARING => 'Hazırlanıyor',
            self::TYPE_ORDER_SHIPPED => 'Kargoya verildi',
            self::TYPE_ORDER_DELIVERED => 'Teslim edildi',
            self::TYPE_CUSTOMER_CREATED => 'Yeni müşteri',
            self::TYPE_PRODUCT_CREATED => 'Yeni ürün',
            self::TYPE_PRODUCT_UPDATED => 'Ürün güncellendi',
        ];
    }

    public function typeLabel(): string
    {
        return self::typeLabels()[$this->type] ?? $this->type;
    }

    public function icon(): string
    {
        return match ($this->type) {
            self::TYPE_ORDER_CREATED => 'bi-bag-plus',
            self::TYPE_ORDER_CANCELLED => 'bi-x-circle',
            self::TYPE_ORDER_PREPARING => 'bi-hourglass-split',
            self::TYPE_ORDER_SHIPPED => 'bi-truck',
            self::TYPE_ORDER_DELIVERED => 'bi-check2-circle',
            self::TYPE_CUSTOMER_CREATED => 'bi-person-plus',
            self::TYPE_PRODUCT_CREATED => 'bi-box-seam',
            self::TYPE_PRODUCT_UPDATED => 'bi-arrow-repeat',
            default => 'bi-bell',
        };
    }
}
