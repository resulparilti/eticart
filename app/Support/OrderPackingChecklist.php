<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ShopifyOrder;

final class OrderPackingChecklist
{
    public const WASH_INSTRUCTIONS = 'wash_instructions';

    public const BRAND_LABELS = 'brand_labels';

    public const TISSUE_PAPER = 'tissue_paper';

    public const TISSUE_STICKER = 'tissue_sticker';

    public const GIFT_BOX = 'gift_box';

    public const GIFT_CARD = 'gift_card';

    public const KRAFT_BOX = 'kraft_box';

    public const BRANDED_MAILER = 'branded_mailer';

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::WASH_INSTRUCTIONS => 'Ürün yıkama talimatları ürünlerin içerisinde mevcut',
            self::BRAND_LABELS => 'Ürünlere marka etiketleri takılı',
            self::TISSUE_PAPER => 'Pelur kağıt kullanıldı',
            self::TISSUE_STICKER => 'Pelur kağıt üzerinde sticker kullanıldı',
            self::GIFT_BOX => 'Seçilen boyuta göre hediye kutusu kullanıldı',
            self::GIFT_CARD => 'Armağan kartı eklendi',
            self::KRAFT_BOX => 'Kraft karton kutu kullanıldı',
            self::BRANDED_MAILER => 'Logo damgalı pat pat poşet kullanıldı',
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function alwaysRequired(): array
    {
        return [
            self::WASH_INSTRUCTIONS,
            self::BRAND_LABELS,
            self::TISSUE_PAPER,
            self::TISSUE_STICKER,
            self::KRAFT_BOX,
            self::BRANDED_MAILER,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function giftKeys(): array
    {
        return [self::GIFT_BOX, self::GIFT_CARD];
    }

    /**
     * @return array<int, string>
     */
    public static function keys(): array
    {
        return array_keys(self::labels());
    }

    /**
     * @return array<int, string>
     */
    public static function requiredKeys(bool $giftBox): array
    {
        $keys = self::alwaysRequired();
        if ($giftBox) {
            $keys = array_merge($keys, self::giftKeys());
        }

        return $keys;
    }

    /**
     * @param  array<string, mixed>|null  $checklist
     */
    public static function isComplete(bool $giftBox, ?array $checklist): bool
    {
        $checklist = is_array($checklist) ? $checklist : [];
        foreach (self::requiredKeys($giftBox) as $key) {
            if (empty($checklist[$key])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{required: bool, size: ?string}
     */
    public static function detectGiftBox(ShopifyOrder $order): array
    {
        $haystack = mb_strtolower(trim(
            (string) $order->notes.' '.$order->customer_name
        ));

        foreach ($order->items as $item) {
            $haystack .= ' '.mb_strtolower(trim(
                (string) $item->product_title.' '.(string) $item->variant_title.' '.(string) $item->sku
            ));
        }

        $required = str_contains($haystack, 'hediye kutu')
            || str_contains($haystack, 'hediyekutu')
            || str_contains($haystack, 'gift box')
            || str_contains($haystack, 'giftbox')
            || str_contains($haystack, 'armağan kart')
            || str_contains($haystack, 'armagan kart');

        $size = null;
        if (preg_match('/\b(küçük|kucuk|small)\b/u', $haystack) === 1) {
            $size = 'Küçük';
        } elseif (preg_match('/\b(büyük|buyuk|large)\b/u', $haystack) === 1) {
            $size = 'Büyük';
        } elseif (preg_match('/\b(orta|medium)\b/u', $haystack) === 1) {
            $size = 'Orta';
        }

        return [
            'required' => $required,
            'size' => $size,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $checklist
     * @return array<int, string>
     */
    public static function checkedLabels(?array $checklist, bool $giftBox): array
    {
        $checklist = is_array($checklist) ? $checklist : [];
        $labels = [];
        foreach (self::requiredKeys($giftBox) as $key) {
            if (! empty($checklist[$key])) {
                $labels[] = self::labels()[$key];
            }
        }

        return $labels;
    }
}
