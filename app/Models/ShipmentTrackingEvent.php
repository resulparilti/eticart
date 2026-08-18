<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShipmentTrackingEvent extends Model
{
    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'shipment_id',
        'event_code',
        'status',
        'title',
        'description',
        'location',
        'occurred_at',
        'fingerprint',
        'raw',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'occurred_at' => 'datetime',
        'raw' => 'array',
    ];

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }
}
