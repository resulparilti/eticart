<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Setting;
use App\Models\Shipment;
use App\Models\ShopifyOrder;

final class ShippingLabelProfile
{
    /**
     * @return array{
     *     name: string,
     *     address: string,
     *     phone: string,
     *     return_cargo_name: string,
     *     return_cargo_code: string,
     *     return_text: string
     * }
     */
    public static function company(): array
    {
        $name = trim((string) Setting::getValue('general_company_name', ''));
        $address = trim((string) Setting::getValue('general_company_address', ''));
        $phone = trim((string) Setting::getValue('general_company_phone', ''));
        $returnName = trim((string) Setting::getValue('general_return_cargo_name', ''));
        $returnCode = trim((string) Setting::getValue('general_return_cargo_code', ''));
        $ref = trim($returnName.' '.$returnCode);

        return [
            'name' => $name,
            'address' => $address,
            'phone' => $phone,
            'return_cargo_name' => $returnName,
            'return_cargo_code' => $returnCode,
            'return_text' => $ref !== ''
                ? "İade ve değişim durumlarında '{$ref}' numarası ile ürünlerinizi ücretsiz olarak geri gönderebilirsiniz."
                : 'İade ve değişim için satıcı ile iletişime geçiniz.',
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function fromShipment(Shipment $shipment, array $overrides = []): array
    {
        $shipment->loadMissing(['order', 'cargoCompany']);
        $order = $shipment->order;
        $locality = $order instanceof ShopifyOrder
            ? $order->resolveShippingLocality()
            : ['city' => '', 'town' => '', 'street' => ''];

        $cargoKey = $shipment->cargoKey();
        $barcode = (string) ($overrides['barcode_value'] ?? $cargoKey);
        $jobId = trim((string) ($overrides['job_id'] ?? $shipment->cargo_job_id ?? ''));

        $street = trim((string) ($locality['street'] ?? ''));
        if ($street === '') {
            $street = trim((string) ($shipment->receiver_address ?: $order?->shipping_address ?: ''));
        }

        $city = trim((string) ($locality['city'] ?? ''));
        $town = trim((string) ($locality['town'] ?? ''));
        if ($city === '' || preg_match('/^\d+$/', $city)) {
            $city = trim((string) ($shipment->receiver_city ?? ''));
            if (preg_match('/^\d+$/', $city)) {
                $city = '';
            }
        }

        $phone = trim((string) ($order?->customer_phone ?: $shipment->receiver_phone ?: ''));

        return array_merge([
            'barcode_value' => $barcode,
            'job_barcode_value' => $jobId !== '' ? $jobId : null,
            'receiver_name' => (string) ($shipment->receiver_name ?: $order?->customer_name ?: ''),
            'receiver_address' => $street,
            'receiver_city' => $city,
            'receiver_town' => $town,
            'receiver_phone' => $phone,
            'order_number' => (string) ($shipment->order_number ?: $order?->order_number ?: ''),
            'job_id' => $jobId,
            'cargo_key' => $cargoKey,
            'company' => self::company(),
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function formatAddress(array $data): string
    {
        $street = trim((string) ($data['receiver_address'] ?? ''));
        $street = preg_replace('/(?:^|\n)\s*\d{5}\s*(?=\n|$)/u', '', $street) ?? $street;
        $street = trim($street);

        $town = trim((string) ($data['receiver_town'] ?? ''));
        $city = trim((string) ($data['receiver_city'] ?? ''));

        if (preg_match('/^\d{4,5}$/', $town)) {
            $town = '';
        }
        if (preg_match('/^\d{4,5}$/', $city)) {
            $city = '';
        }

        $locality = [];
        if ($town !== '') {
            $locality[] = $town;
        }
        if ($city !== '' && ($town === '' || mb_strtoupper($city, 'UTF-8') !== mb_strtoupper($town, 'UTF-8'))) {
            $locality[] = $city;
        }

        return implode("\n", array_filter([
            $street,
            implode(', ', $locality),
        ], static fn (string $value): bool => $value !== ''));
    }
}
