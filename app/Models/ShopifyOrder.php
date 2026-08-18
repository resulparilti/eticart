<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\ShopifyShippingAddress;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class ShopifyOrder extends Model
{
    use SoftDeletes;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'shopify_order_id',
        'order_number',
        'user_id',
        'customer_id',
        'customer_name',
        'customer_email',
        'customer_phone',
        'shipping_address',
        'shipping_city',
        'shipping_province',
        'shipping_zip',
        'total_price',
        'currency',
        'payment_status',
        'fulfillment_status',
        'order_items',
        'notes',
        'invoice_path',
        'invoice_original_name',
        'invoice_uploaded_at',
        'invoice_token',
        'shopify_created_at',
        'synced_at',
        'shopify_needs_push',
        'shopify_pushed_at',
        'uyumsoft_order_id',
        'uyumsoft_invoice_id',
        'uyumsoft_invoice_no',
        'uyumsoft_pushed_at',
        'uyumsoft_last_error',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'order_items' => 'array',
        'total_price' => 'decimal:2',
        'shopify_created_at' => 'datetime',
        'synced_at' => 'datetime',
        'invoice_uploaded_at' => 'datetime',
        'shopify_needs_push' => 'boolean',
        'shopify_pushed_at' => 'datetime',
        'uyumsoft_pushed_at' => 'datetime',
    ];

    /**
     * Assigned user.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Linked customer record.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(ShopifyCustomer::class, 'customer_id');
    }

    /**
     * Order line items.
     */
    public function items(): HasMany
    {
        return $this->hasMany(ShopifyOrderItem::class);
    }

    /**
     * Related shipments.
     */
    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    /**
     * Latest non-cancelled shipment that was sent to a cargo company.
     */
    public function latestCargoShipment(): ?Shipment
    {
        $shipments = $this->relationLoaded('shipments')
            ? $this->shipments
            : $this->shipments()->with('cargoCompany')->get();

        return $shipments
            ->filter(static function (Shipment $shipment): bool {
                return filled($shipment->cargo_company_id)
                    && $shipment->status !== Shipment::STATUS_CANCELLED
                    && filled($shipment->tracking_number);
            })
            ->sortByDesc('id')
            ->first();
    }

    /**
     * Latest non-cancelled shipment (tracking optional).
     */
    public function latestActiveShipment(): ?Shipment
    {
        $shipments = $this->relationLoaded('shipments')
            ? $this->shipments
            : $this->shipments()->with('cargoCompany')->get();

        return $shipments
            ->filter(static fn (Shipment $shipment): bool => $shipment->status !== Shipment::STATUS_CANCELLED)
            ->sortByDesc('id')
            ->first();
    }

    /**
     * Orders whose local status / invoice / cargo should be written to Shopify.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<ShopifyOrder>  $query
     * @return \Illuminate\Database\Eloquent\Builder<ShopifyOrder>
     */
    public function scopeNeedsShopifyPush($query)
    {
        return $query
            ->whereNotNull('shopify_order_id')
            ->where(function ($builder): void {
                $builder->where('shopify_needs_push', true)
                    ->orWhere(function ($pending): void {
                        $pending->whereNull('shopify_pushed_at')
                            ->where(function ($local): void {
                                $local->whereIn('fulfillment_status', ['preparing', 'fulfilled', 'delivered'])
                                    ->orWhereNotNull('invoice_path');
                            });
                    });
            });
    }

    public function markNeedsShopifyPush(): void
    {
        $this->forceFill(['shopify_needs_push' => true])->save();
    }

    /**
     * Shopify satışları henüz UyumSoft’a yazılmamış siparişler.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<ShopifyOrder>  $query
     * @return \Illuminate\Database\Eloquent\Builder<ShopifyOrder>
     */
    public function scopeNeedsUyumsoftPush($query)
    {
        return $query
            ->whereNull('uyumsoft_order_id')
            ->whereHas('items')
            ->where(function ($builder): void {
                $builder->whereNull('fulfillment_status')
                    ->orWhereNotIn('fulfillment_status', ['cancelled', 'restocked']);
            })
            ->where(function ($builder): void {
                $builder->whereNull('payment_status')
                    ->orWhereNotIn('payment_status', ['refunded', 'voided']);
            });
    }

    /**
     * Resolve city (il) and town (ilçe) for cargo APIs.
     *
     * Shopify: province=il, city=ilçe, zip=posta kodu. Posta kodu asla il olarak gönderilmez.
     *
     * @return array{city: string, town: string, street: string, province: string, zip: string}
     */
    public function resolveShippingLocality(): array
    {
        return ShopifyShippingAddress::fromOrderFields(
            $this->shipping_address,
            $this->shipping_city,
            $this->shipping_province ?? null,
            $this->shipping_zip ?? null
        );
    }

    public function hasInvoice(): bool
    {
        return filled($this->invoice_path);
    }

    public function ensureInvoiceToken(): string
    {
        if (filled($this->invoice_token)) {
            return (string) $this->invoice_token;
        }

        $token = $this->newInvoiceToken();
        $this->forceFill(['invoice_token' => $token])->save();

        return $token;
    }

    public function newInvoiceToken(): string
    {
        return bin2hex(random_bytes(24));
    }

    public function invoiceUrl(): ?string
    {
        if (! filled($this->invoice_path)) {
            return null;
        }

        $url = route('invoices.public', $this->ensureInvoiceToken());
        if (
            str_starts_with($url, 'http://')
            && ! str_contains($url, 'localhost')
            && ! str_contains($url, '127.0.0.1')
        ) {
            $url = 'https://'.substr($url, 7);
        }

        return $url;
    }

    public function invoiceAttachmentName(): string
    {
        $order = trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', (string) $this->order_number), '-');
        if ($order === '') {
            $order = (string) $this->id;
        }

        $ext = strtolower((string) pathinfo((string) ($this->invoice_original_name ?: $this->invoice_path), PATHINFO_EXTENSION));
        if (! in_array($ext, ['pdf', 'png', 'jpg', 'jpeg', 'webp'], true)) {
            $ext = 'pdf';
        }

        return 'Fatura-'.$order.'.'.$ext;
    }

    public function invoiceMimeType(): string
    {
        $ext = strtolower((string) pathinfo($this->invoiceAttachmentName(), PATHINFO_EXTENSION));

        return match ($ext) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            default => 'application/pdf',
        };
    }

    public function invoiceIsAttachable(): bool
    {
        try {
            $path = (string) $this->invoice_path;
            if ($path === '' || ! Storage::disk('public')->exists($path)) {
                return false;
            }

            return Storage::disk('public')->size($path) <= (4 * 1024 * 1024);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Whether the order note currently contains an auto-inserted invoice line.
     */
    public function hasInvoiceNoteLine(): bool
    {
        return self::noteContainsInvoiceLine($this->notes);
    }

    public static function noteContainsInvoiceLine(?string $notes): bool
    {
        return (bool) preg_match('/^[ \t]*Fatura\s*:/mu', (string) $notes);
    }

    /**
     * Remove auto-inserted "Fatura: {url}" lines from an order note.
     */
    public static function stripInvoiceLines(?string $notes): ?string
    {
        if ($notes === null) {
            return null;
        }

        $cleaned = preg_replace('/^[ \t]*Fatura\s*:[^\r\n]*\r?$/mu', '', $notes) ?? $notes;
        $cleaned = trim((string) preg_replace("/\n{3,}/", "\n\n", $cleaned));

        return $cleaned === '' ? null : $cleaned;
    }

    /**
     * Append or replace the invoice URL line in an order note.
     */
    public static function appendInvoiceLine(?string $notes, string $invoiceUrl): string
    {
        $invoiceUrl = trim($invoiceUrl);
        $cleaned = self::stripInvoiceLines($notes) ?? '';
        $line = 'Fatura: '.$invoiceUrl;

        return $cleaned === '' ? $line : $cleaned."\n\n".$line;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany<Notification, $this>
     */
    public function notifications(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(Notification::class, 'notifiable');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Notification>
     */
    public function shipmentInvoiceMails(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->notifications()
            ->where('type', 'mail')
            ->where('body', 'shipment-invoice')
            ->latest()
            ->limit(15)
            ->get();
    }
}
