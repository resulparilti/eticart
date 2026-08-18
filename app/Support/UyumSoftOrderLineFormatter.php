<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ShopifyOrderItem;
use App\Models\UyumSoftProduct;

/**
 * Builds UyumSoft order line display fields (itemName, barcode notes) from Shopify items.
 */
final class UyumSoftOrderLineFormatter
{
    public function __construct(
        private readonly bool $includeTitle,
        private readonly bool $includeVariant,
        private readonly bool $includeBarcode,
    ) {
    }

    /**
     * @return array{itemName?: string, barCode?: string, note1?: string}
     */
    public function extras(ShopifyOrderItem $item, ?UyumSoftProduct $matchedProduct = null): array
    {
        $extras = [];

        if ($this->includeTitle) {
            $name = $this->formatItemName($item);
            if ($name !== '') {
                $extras['itemName'] = $name;
            }
        }

        if ($this->includeBarcode) {
            $barcode = $this->resolveBarcode($item, $matchedProduct);
            if ($barcode !== '') {
                $extras['barCode'] = $barcode;
                $extras['note1'] = 'Barkod: '.$barcode;
            }
        }

        return $extras;
    }

    public function formatItemName(ShopifyOrderItem $item): string
    {
        $title = trim((string) $item->product_title);
        if ($title === '') {
            return '';
        }

        if (! $this->includeVariant) {
            return $title;
        }

        $variant = trim((string) $item->variant_title);
        if ($variant === '' || strcasecmp($variant, 'Default Title') === 0) {
            return $title;
        }

        return $title.' '.$variant;
    }

    public function resolveBarcode(ShopifyOrderItem $item, ?UyumSoftProduct $matchedProduct = null): string
    {
        $barcode = trim((string) $item->barcode);
        if ($barcode !== '') {
            return $barcode;
        }

        $sku = trim((string) $item->sku);
        if ($matchedProduct === null && $sku !== '') {
            $matchedProduct = UyumSoftProduct::query()
                ->where(function ($query) use ($sku): void {
                    $query->where('sku', $sku)
                        ->orWhere('barcode', $sku)
                        ->orWhere('uyumsoft_id', $sku)
                        ->orWhere('variant_info', 'like', '%'.$sku.'%');
                })
                ->first();
        }

        if ($matchedProduct === null) {
            return '';
        }

        foreach ($matchedProduct->variant_info['variants'] ?? [] as $variant) {
            if (! is_array($variant)) {
                continue;
            }

            $matchesSku = $sku !== '' && (($variant['sku'] ?? null) === $sku || ($variant['barcode'] ?? null) === $sku);
            if ($matchesSku && filled($variant['barcode'] ?? null)) {
                return (string) $variant['barcode'];
            }
        }

        return trim((string) ($matchedProduct->barcode ?? ''));
    }

    public static function fromSettings(): self
    {
        return new self(
            includeTitle: (string) \App\Models\Setting::getValue('uyumsoft_order_line_include_title', '1') === '1',
            includeVariant: (string) \App\Models\Setting::getValue('uyumsoft_order_line_include_variant', '1') === '1',
            includeBarcode: (string) \App\Models\Setting::getValue('uyumsoft_order_line_include_barcode', '1') === '1',
        );
    }
}
