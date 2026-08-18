<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Shipment extends Model
{
    use SoftDeletes;

    public const STATUS_PENDING = 'pending';
    public const STATUS_SHIPPED = 'shipped';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_RETURNED = 'returned';
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'shopify_order_id',
        'cargo_company_id',
        'order_number',
        'tracking_number',
        'cargo_key',
        'cargo_job_id',
        'tracking_url',
        'status',
        'receiver_name',
        'receiver_phone',
        'receiver_address',
        'receiver_city',
        'weight',
        'cargo_cost',
        'insurance',
        'amount',
        'label_path',
        'invoice_path',
        'notes',
        'provider_payload',
        'shipped_at',
        'delivered_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'weight' => 'decimal:2',
        'cargo_cost' => 'decimal:2',
        'insurance' => 'decimal:2',
        'amount' => 'decimal:2',
        'provider_payload' => 'array',
        'shipped_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    /**
     * Related Shopify order.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(ShopifyOrder::class, 'shopify_order_id');
    }

    /**
     * Cargo company.
     */
    public function cargoCompany(): BelongsTo
    {
        return $this->belongsTo(CargoCompany::class);
    }

    /**
     * Yurtiçi / provider kargo hareketleri.
     */
    public function trackingEvents(): HasMany
    {
        return $this->hasMany(ShipmentTrackingEvent::class)->orderByDesc('occurred_at')->orderByDesc('id');
    }

    /**
     * Halka açık takip no (şube kabulü sonrası). cargoKey referansı sayılmaz.
     */
    public function publicTrackingNumber(): ?string
    {
        $tracking = trim((string) ($this->tracking_number ?? ''));
        if ($tracking === '') {
            return null;
        }

        $cargoKey = trim((string) ($this->cargo_key ?? ''));
        if ($cargoKey !== '' && strcasecmp($tracking, $cargoKey) === 0) {
            return null;
        }

        if (str_starts_with(strtoupper($tracking), 'YKLOCAL')) {
            return null;
        }

        return $tracking;
    }

    /**
     * Public logo path for provider badge on order lists.
     */
    public function cargoLogoUrl(): ?string
    {
        $company = $this->cargoCompany;

        return $company?->logoUrl();
    }

    public function cargoKey(): string
    {
        return trim((string) ($this->cargo_key ?: $this->tracking_number ?: ''));
    }

    public function canCancel(): bool
    {
        return ! in_array($this->status, [
            self::STATUS_CANCELLED,
            self::STATUS_DELIVERED,
            self::STATUS_RETURNED,
        ], true);
    }
}
