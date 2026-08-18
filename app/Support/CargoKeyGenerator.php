<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Shipment;
use Illuminate\Support\Str;

/**
 * Yurtiçi cargoKey: tam sayısal, benzersiz, max 20 hane.
 * Sona Shopify sipariş numarası eklenir (ayırt etme / tekrar önleme).
 */
final class CargoKeyGenerator
{
    public const MAX_LENGTH = 20;

    /**
     * Yeni benzersiz sayısal cargoKey üretir.
     * Örnek: 26081310451234 + 1088 → …1088 (sipariş #1088)
     */
    public static function generateUnique(?string $orderNumber = null): string
    {
        $orderDigits = self::orderDigits($orderNumber);

        for ($attempt = 0; $attempt < 25; $attempt++) {
            $key = self::buildCandidate(null, $orderDigits);

            if ($key !== '' && ! self::exists($key)) {
                return $key;
            }
        }

        $fallback = now()->format('ymdHis').random_int(1000, 9999).$orderDigits;

        return Str::limit(self::sanitize($fallback), self::MAX_LENGTH, '');
    }

    /**
     * Sadece rakamları alır; boşsa boş string döner.
     */
    public static function sanitize(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        return Str::limit($digits, self::MAX_LENGTH, '');
    }

    /**
     * Sipariş numarasından rakamlar (#1088 → 1088).
     */
    public static function orderDigits(?string $orderNumber): string
    {
        if ($orderNumber === null || trim($orderNumber) === '') {
            return '';
        }

        return preg_replace('/\D+/', '', $orderNumber) ?? '';
    }

    private static function buildCandidate(?string $forced, string $orderDigits = ''): string
    {
        if ($forced !== null) {
            return Str::limit(self::sanitize($forced), self::MAX_LENGTH, '');
        }

        $orderDigits = Str::limit($orderDigits, self::MAX_LENGTH, '');
        $prefixLen = self::MAX_LENGTH - strlen($orderDigits);

        if ($prefixLen <= 0) {
            // Sipariş no zaten 20+ hane: son 20 haneyi kullan.
            return substr($orderDigits, -self::MAX_LENGTH);
        }

        // Zaman + rastgele önek; sonda sipariş numarası.
        $base = now()->format('ymdHis').str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        $prefix = Str::limit($base, $prefixLen, '');

        return $prefix.$orderDigits;
    }

    private static function exists(string $key): bool
    {
        if ($key === '') {
            return true;
        }

        return Shipment::query()
            ->where('cargo_key', $key)
            ->orWhere('tracking_number', $key)
            ->exists();
    }
}
