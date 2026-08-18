<?php

declare(strict_types=1);

namespace App\Support;

final class StatusLabels
{
    /**
     * @return array<string, string>
     */
    public static function fulfillmentMap(): array
    {
        return [
            'unfulfilled' => 'Karşılanmadı',
            'preparing' => 'Hazırlanıyor',
            'partial' => 'Kısmen karşılandı',
            'fulfilled' => 'Kargoya verildi',
            'delivered' => 'Kargo teslim edildi',
            'restocked' => 'Stoğa iade',
            'cancelled' => 'İptal',
            'null' => 'Karşılanmadı',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function paymentMap(): array
    {
        return [
            'pending' => 'Beklemede',
            'authorized' => 'Yetkilendirildi',
            'paid' => 'Ödendi',
            'partially_paid' => 'Kısmen ödendi',
            'partially_refunded' => 'Kısmen iade',
            'refunded' => 'İade edildi',
            'voided' => 'Geçersiz',
            'unpaid' => 'Ödenmedi',
            'expired' => 'Süresi doldu',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function shipmentMap(): array
    {
        return [
            'pending' => 'Hazırlanıyor',
            'shipped' => 'Kargoda',
            'delivered' => 'Teslim edildi',
            'returned' => 'İade',
            'cancelled' => 'İptal',
        ];
    }

    public static function fulfillment(?string $status): string
    {
        return self::label(self::fulfillmentMap(), $status);
    }

    public static function payment(?string $status): string
    {
        return self::label(self::paymentMap(), $status);
    }

    public static function shipment(?string $status): string
    {
        return self::label(self::shipmentMap(), $status);
    }

    public static function fulfillmentBadge(?string $status): string
    {
        return match (strtolower((string) $status)) {
            'fulfilled' => 'success',
            'delivered' => 'success',
            'preparing' => 'info',
            'partial' => 'warning',
            'cancelled', 'restocked' => 'danger',
            default => 'secondary',
        };
    }

    public static function paymentBadge(?string $status): string
    {
        return match (strtolower((string) $status)) {
            'paid' => 'success',
            'partially_paid', 'authorized' => 'info',
            'pending', 'unpaid' => 'warning',
            'refunded', 'partially_refunded', 'voided', 'expired' => 'danger',
            default => 'secondary',
        };
    }

    public static function shipmentBadge(?string $status): string
    {
        return match (strtolower((string) $status)) {
            'delivered' => 'success',
            'shipped' => 'info',
            'pending' => 'warning',
            'returned', 'cancelled' => 'danger',
            default => 'secondary',
        };
    }

    /**
     * @param  array<string, string>  $map
     */
    private static function label(array $map, ?string $status): string
    {
        if ($status === null || trim($status) === '') {
            return '—';
        }

        $key = strtolower(trim($status));

        return $map[$key] ?? $status;
    }
}
