<?php

declare(strict_types=1);

namespace App\Support;

final class ShopifyShippingAddress
{
    /**
     * Shopify shipping/billing/default_address bloğundan kargo alanları.
     *
     * Shopify TR:
     * - province = il (Bursa)
     * - city = genelde ilçe (Mudanya / Osmangazi); müşteri bazen ile city yazar
     * - zip = posta kodu (16440) — asla il/ilçe olarak gönderilmez
     *
     * @param  array<string, mixed>  $shipping
     * @param  array<string, mixed>  $billing
     * @param  array<string, mixed>  $fallback
     * @param  array<int, array<string, mixed>>  $noteAttributes
     * @return array{
     *     street: string,
     *     city: string,
     *     town: string,
     *     province: string,
     *     zip: string,
     *     country: string
     * }
     */
    public static function fromShopify(array $shipping, array $billing = [], array $fallback = [], array $noteAttributes = []): array
    {
        $pick = static function (string $key) use ($shipping, $billing, $fallback): string {
            foreach ([$shipping, $billing, $fallback] as $block) {
                $value = trim((string) ($block[$key] ?? ''));
                if ($value !== '') {
                    return $value;
                }
            }

            return '';
        };

        $custom = self::fromNoteAttributes($noteAttributes);

        $streetParts = array_filter([
            $pick('company'),
            $pick('address1'),
            $pick('address2'),
        ], static fn (string $v): bool => $v !== '');

        $province = TurkeyLocality::canonicalProvince($pick('province'))
            ?? TurkeyLocality::canonicalProvince($pick('province_code'))
            ?? TurkeyLocality::canonicalProvince($custom['province'] ?? '')
            ?? '';
        $shopifyCity = $pick('city') !== '' ? $pick('city') : (string) ($custom['town'] ?? '');
        $zip = self::normalizeZip($pick('zip')) ?: self::normalizeZip((string) ($custom['zip'] ?? ''));
        $street = $streetParts !== [] ? implode("\n", $streetParts) : '';

        if ($province === '' && ($custom['province'] ?? '') !== '') {
            $province = (string) $custom['province'];
        }

        return self::resolve($street, $shopifyCity, $province, $zip);
    }

    /**
     * Kayıtlı sipariş satırından il / ilçe / sokak (eski birleşik adresler dahil).
     *
     * @return array{city: string, town: string, street: string, province: string, zip: string}
     */
    public static function fromOrderFields(
        ?string $address,
        ?string $shopifyCity,
        ?string $province = null,
        ?string $zip = null
    ): array {
        return self::resolve(
            (string) $address,
            (string) $shopifyCity,
            (string) $province,
            (string) $zip
        );
    }

    /**
     * @return array{city: string, town: string, street: string, province: string, zip: string, country: string}
     */
    public static function resolve(string $address, string $shopifyCity, string $province = '', string $zip = ''): array
    {
        $tokens = self::tokens($address);
        $zip = self::normalizeZip($zip) ?: self::extractZip($tokens);
        $province = TurkeyLocality::canonicalProvince($province) ?? '';

        if ($province === '') {
            $province = self::provinceFromTokens($tokens)
                ?? TurkeyLocality::findProvinceInText($address)
                ?? TurkeyLocality::findProvinceInText($shopifyCity)
                ?? TurkeyLocality::provinceForDistrict($shopifyCity)
                ?? '';
        }

        $shopifyCity = trim($shopifyCity);
        $cityAsProvince = TurkeyLocality::canonicalProvince($shopifyCity);

        // Shopify city bazen ildir (Bursa), bazen ilçedir (Osmangazi).
        if ($province === '' && $cityAsProvince !== null) {
            $province = $cityAsProvince;
        }

        $town = '';
        if ($shopifyCity !== '' && ! TurkeyLocality::isPostalCode($shopifyCity)) {
            if ($cityAsProvince === null || ($province !== '' && TurkeyLocality::normalize($cityAsProvince) !== TurkeyLocality::normalize($province))) {
                $town = $shopifyCity;
            }
        }

        if ($town === '') {
            $town = self::districtFromTokens($tokens, $province, $zip) ?? '';
        }

        if ($town === '' && $province !== '') {
            $town = $province;
        }

        if ($province === '' && $shopifyCity !== '' && ! TurkeyLocality::isPostalCode($shopifyCity)) {
            $province = $shopifyCity;
        }

        if ($town === '' && $province !== '') {
            $town = $province;
        }

        $street = self::streetFromAddress($address, $province, $town, $zip);

        return [
            'city' => mb_strtoupper($province, 'UTF-8'),
            'town' => mb_strtoupper($town, 'UTF-8'),
            'street' => $street,
            'province' => $province,
            'zip' => $zip,
            'country' => 'TR',
        ];
    }

    /**
     * @param  array<int, string>  $tokens
     */
    private static function extractZip(array $tokens): string
    {
        foreach (array_reverse($tokens) as $token) {
            if (TurkeyLocality::isPostalCode($token)) {
                return self::normalizeZip($token);
            }
        }

        return '';
    }

    /**
     * @param  array<int, string>  $tokens
     */
    private static function provinceFromTokens(array $tokens): ?string
    {
        foreach (array_reverse($tokens) as $token) {
            if (TurkeyLocality::isCountry($token) || TurkeyLocality::isPostalCode($token)) {
                continue;
            }
            $province = TurkeyLocality::canonicalProvince($token);
            if ($province !== null) {
                return $province;
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $tokens
     */
    private static function districtFromTokens(array $tokens, string $province, string $zip): ?string
    {
        $provinceKey = $province !== '' ? TurkeyLocality::normalize($province) : '';

        foreach (array_reverse($tokens) as $token) {
            if (TurkeyLocality::isCountry($token) || TurkeyLocality::isPostalCode($token)) {
                continue;
            }
            if ($zip !== '' && self::normalizeZip($token) === $zip) {
                continue;
            }
            $asProvince = TurkeyLocality::canonicalProvince($token);
            if ($asProvince !== null && $provinceKey !== '' && TurkeyLocality::normalize($asProvince) === $provinceKey) {
                continue;
            }
            if (mb_strlen($token) < 3 || mb_strlen($token) > 40) {
                continue;
            }
            if (preg_match('/\d/', $token)) {
                continue;
            }
            if (preg_match('/\b(MAH|MAHALLE|CAD|CADDESI|CADDE|SOK|SOKAK|NO|APT|APARTMAN|BLOK|BULVAR|BULVARI)\b/iu', $token)) {
                continue;
            }

            return $token;
        }

        return null;
    }

    private static function streetFromAddress(string $address, string $province, string $town, string $zip): string
    {
        $parts = preg_split('/\s*,\s*/u', $address) ?: [];
        $keep = [];

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (TurkeyLocality::isCountry($part) || TurkeyLocality::isPostalCode($part)) {
                continue;
            }
            if ($zip !== '' && self::normalizeZip($part) === $zip) {
                continue;
            }
            $asProvince = TurkeyLocality::canonicalProvince($part);
            if ($asProvince !== null && $province !== '' && TurkeyLocality::normalize($asProvince) === TurkeyLocality::normalize($province)) {
                continue;
            }
            if ($town !== '' && TurkeyLocality::normalize($part) === TurkeyLocality::normalize($town)) {
                continue;
            }
            $keep[] = $part;
        }

        $street = trim(implode(', ', $keep));
        $street = preg_replace('/(?:^|[,\s])\d{5}(?=$|[,\s])/u', '', $street) ?? $street;
        $street = trim($street, " \t,");

        return $street !== '' ? $street : trim(preg_replace('/\b\d{5}\b/', '', $address) ?? $address);
    }

    /**
     * @param  array<int, mixed>  $noteAttributes
     * @return array{province: string, town: string, zip: string}
     */
    private static function fromNoteAttributes(array $noteAttributes): array
    {
        $province = '';
        $town = '';
        $zip = '';

        foreach ($noteAttributes as $attribute) {
            if (! is_array($attribute)) {
                continue;
            }
            $name = TurkeyLocality::normalize((string) ($attribute['name'] ?? ''));
            $value = trim((string) ($attribute['value'] ?? ''));
            if ($value === '') {
                continue;
            }

            if (in_array($name, ['IL', 'IL ADI', 'SEHIR', 'SEHIR ADI', 'PROVINCE'], true)) {
                $province = TurkeyLocality::canonicalProvince($value) ?? $province;
            }
            if (str_contains($name, 'ILCE') || in_array($name, ['DISTRICT', 'TOWN'], true)) {
                $town = $value;
            }
            if (str_contains($name, 'POSTA') || in_array($name, ['ZIP', 'POSTAL CODE', 'PK'], true)) {
                $zip = self::normalizeZip($value) ?: $zip;
            }
        }

        return compact('province', 'town', 'zip');
    }

    /**
     * @return array<int, string>
     */
    private static function tokens(string $address): array
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $address);
        $parts = preg_split('/\s*[,;\/|\n]\s*/u', $normalized) ?: [];
        $tokens = [];

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $tokens[] = $part;
            foreach (preg_split('/\s+/u', $part) ?: [] as $word) {
                $word = trim($word, " \t.");
                if ($word !== '' && $word !== $part) {
                    $tokens[] = $word;
                }
            }
        }

        return array_values(array_unique($tokens));
    }

    private static function normalizeZip(string $zip): string
    {
        $digits = preg_replace('/\D+/', '', $zip) ?? '';

        return strlen($digits) >= 4 && strlen($digits) <= 5 ? $digits : '';
    }
}
