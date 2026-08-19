<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ShopifyProduct;
use Illuminate\Support\Collection;

final class ShopifyMetafieldFormatter
{
    /**
     * @param  array<int, array<string, mixed>>  $fields
     * @param  Collection<string, ShopifyProduct>|array<string, ShopifyProduct>  $productsByShopifyId
     * @return array<int, array<string, mixed>>
     */
    public static function present(array $fields, Collection|array $productsByShopifyId = []): array
    {
        $lookup = $productsByShopifyId instanceof Collection
            ? $productsByShopifyId
            : collect($productsByShopifyId);

        $rows = [];
        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }

            $namespace = trim((string) ($field['namespace'] ?? ''));
            $key = trim((string) ($field['key'] ?? ''));
            if ($namespace === '' || $key === '') {
                continue;
            }

            $fullKey = $namespace.'.'.$key;
            $parsed = self::decodeValue($field['value'] ?? null);
            $kind = self::detectKind($fullKey, (string) ($field['type'] ?? ''), $parsed);

            $rows[] = [
                'key' => $fullKey,
                'label' => self::label($namespace, $key),
                'kind' => $kind,
                'html' => $kind === 'richtext' ? self::richTextToHtml($parsed) : null,
                'products' => $kind === 'products' ? self::productCards($parsed, $lookup) : [],
                'text' => $kind === 'text' ? self::plainText($parsed) : null,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, string>
     */
    public static function productIdsFromFields(array $fields): array
    {
        $ids = [];
        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }
            foreach (self::extractProductIds(self::decodeValue($field['value'] ?? null)) as $id) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    public static function label(string $namespace, string $key): string
    {
        $full = $namespace.'.'.$key;

        return match ($full) {
            'custom.kombin_urunler' => 'Kombin ürünler',
            'custom.buy_together_product' => 'Birlikte al',
            'custom.bakim_talimati' => 'Bakım talimatı',
            default => self::humanize($key),
        };
    }

    public static function cdnWidth(string $url, int $width): string
    {
        $url = trim($url);
        if ($url === '' || $width <= 0) {
            return $url;
        }

        $host = (string) (parse_url($url, PHP_URL_HOST) ?? '');
        if (! str_contains($host, 'shopify')) {
            return $url;
        }

        $url = (string) preg_replace('/([?&])width=\d+/', '$1', $url);
        $url = (string) preg_replace('/[?&]$/', '', $url);
        $separator = str_contains($url, '?') ? '&' : '?';

        return $url.$separator.'width='.$width;
    }

    public static function decodeValue(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        $first = $trimmed[0];
        if ($first !== '{' && $first !== '[') {
            return $trimmed;
        }

        $decoded = json_decode($trimmed, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $trimmed;
    }

    /**
     * @return array<int, string>
     */
    public static function extractProductIds(mixed $value): array
    {
        $items = is_array($value) && ! self::isRichText($value)
            ? $value
            : [$value];

        $ids = [];
        foreach ($items as $item) {
            if (! is_string($item)) {
                continue;
            }
            if (preg_match('#gid://shopify/Product/(\d+)#', $item, $matches) === 1) {
                $ids[] = $matches[1];
            }
        }

        return array_values(array_unique($ids));
    }

    public static function richTextToHtml(mixed $node): string
    {
        if (is_string($node)) {
            $decoded = self::decodeValue($node);
            $node = is_array($decoded) ? $decoded : ['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => $node]]];
        }
        if (! is_array($node)) {
            return '';
        }

        $html = self::nodeToHtml($node);

        return (string) (HtmlSanitizer::rich($html) ?? '');
    }

    private static function detectKind(string $fullKey, string $type, mixed $parsed): string
    {
        if (
            str_contains($type, 'rich_text')
            || $fullKey === 'custom.bakim_talimati'
            || self::isRichText($parsed)
        ) {
            return 'richtext';
        }

        if (
            str_contains($type, 'product_reference')
            || in_array($fullKey, ['custom.kombin_urunler', 'custom.buy_together_product'], true)
            || self::extractProductIds($parsed) !== []
        ) {
            return 'products';
        }

        return 'text';
    }

    /**
     * @param  Collection<string, ShopifyProduct>  $lookup
     * @return array<int, array{id: string, title: string, local_id: ?int, mirror_id: ?int, image: ?string}>
     */
    private static function productCards(mixed $parsed, Collection $lookup): array
    {
        $cards = [];
        foreach (self::extractProductIds($parsed) as $id) {
            $mirror = $lookup->get($id);
            $local = $mirror?->uyumSoftProduct;
            $localImages = $local?->imageUrls() ?? [];
            $mirrorImages = $mirror?->imageRows() ?? [];
            $image = $localImages[0] ?? ($mirrorImages[0]['src'] ?? null);

            $cards[] = [
                'id' => $id,
                'title' => $local?->title ?: ($mirror?->title ?: 'Shopify ürün #'.$id),
                'local_id' => $local?->id,
                'mirror_id' => $mirror?->id,
                'image' => is_string($image) && $image !== '' ? $image : null,
            ];
        }

        return $cards;
    }

    private static function plainText(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'Evet' : 'Hayır';
        }
        if (is_scalar($value) || $value === null) {
            return trim((string) $value);
        }

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? $encoded : '';
    }

    private static function isRichText(mixed $value): bool
    {
        return is_array($value) && ($value['type'] ?? null) === 'root';
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private static function nodeToHtml(array $node): string
    {
        $type = (string) ($node['type'] ?? '');
        if ($type === 'text') {
            $value = e((string) ($node['value'] ?? ''));
            if ($value === '') {
                return '';
            }
            if (! empty($node['bold'])) {
                $value = '<strong>'.$value.'</strong>';
            }
            if (! empty($node['italic'])) {
                $value = '<em>'.$value.'</em>';
            }

            return $value;
        }

        $inner = '';
        foreach ($node['children'] ?? [] as $child) {
            if (is_array($child)) {
                $inner .= self::nodeToHtml($child);
            }
        }

        return match ($type) {
            'root' => $inner,
            'paragraph' => '<p>'.$inner.'</p>',
            'heading' => '<h'.min(6, max(2, (int) ($node['level'] ?? 3))).'>'.$inner.'</h'.min(6, max(2, (int) ($node['level'] ?? 3))).'>',
            'list' => (($node['listType'] ?? '') === 'ordered' ? '<ol>' : '<ul>').$inner.(($node['listType'] ?? '') === 'ordered' ? '</ol>' : '</ul>'),
            'list-item' => '<li>'.$inner.'</li>',
            'link' => '<a href="'.e((string) ($node['url'] ?? '#')).'">'.$inner.'</a>',
            default => $inner,
        };
    }

    private static function humanize(string $key): string
    {
        $label = str_replace(['_', '-'], ' ', $key);

        return $label !== '' ? mb_convert_case($label, MB_CASE_TITLE, 'UTF-8') : $key;
    }
}
