<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ShopifyCustomer extends Model
{
    use SoftDeletes;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'shopify_customer_id',
        'email',
        'phone',
        'first_name',
        'last_name',
        'full_name',
        'company',
        'address',
        'city',
        'province',
        'country',
        'zip',
        'orders_count',
        'total_spent',
        'currency',
        'tax_exempt',
        'verified_email',
        'state',
        'tags',
        'addresses',
        'raw',
        'note',
        'shopify_created_at',
        'shopify_updated_at',
        'last_order_at',
        'last_sync',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'orders_count' => 'integer',
        'total_spent' => 'decimal:2',
        'tax_exempt' => 'boolean',
        'verified_email' => 'boolean',
        'tags' => 'array',
        'addresses' => 'array',
        'raw' => 'array',
        'shopify_created_at' => 'datetime',
        'shopify_updated_at' => 'datetime',
        'last_order_at' => 'datetime',
        'last_sync' => 'datetime',
    ];

    public function orders(): HasMany
    {
        return $this->hasMany(ShopifyOrder::class, 'customer_id');
    }

    public function displayName(): string
    {
        if (filled($this->full_name)) {
            return (string) $this->full_name;
        }

        $combined = trim(($this->first_name ?? '').' '.($this->last_name ?? ''));
        if ($combined !== '') {
            return $combined;
        }

        return (string) ($this->email ?: $this->phone ?: 'İsimsiz müşteri');
    }

    /**
     * @return array<int, string>
     */
    public function tagList(): array
    {
        $tags = $this->tags;
        if (is_string($tags)) {
            return array_values(array_filter(array_map('trim', explode(',', $tags))));
        }

        return is_array($tags) ? array_values(array_filter($tags)) : [];
    }
}
