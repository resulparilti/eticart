<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\ShopifyMetafieldFormatter;
use PHPUnit\Framework\TestCase;

class ShopifyMetafieldFormatterTest extends TestCase
{
    public function test_rich_text_care_instructions_become_readable_html(): void
    {
        $value = [
            'type' => 'root',
            'children' => [
                ['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => 'Akrilik iplik pratiktir.']]],
                [
                    'type' => 'list',
                    'listType' => 'unordered',
                    'children' => [
                        ['type' => 'list-item', 'children' => [
                            ['type' => 'text', 'value' => 'Makinede '],
                            ['type' => 'text', 'value' => '30°C', 'bold' => true],
                            ['type' => 'text', 'value' => '’de yıkayın.'],
                        ]],
                    ],
                ],
            ],
        ];

        $html = ShopifyMetafieldFormatter::richTextToHtml($value);

        $this->assertStringContainsString('<p>Akrilik iplik pratiktir.</p>', $html);
        $this->assertStringContainsString('<ul>', $html);
        $this->assertStringContainsString('<strong>30°C</strong>', $html);
    }

    public function test_product_gids_are_extracted_from_list_and_single_values(): void
    {
        $this->assertSame(
            ['9299859570908', '9299859701980'],
            ShopifyMetafieldFormatter::extractProductIds([
                'gid://shopify/Product/9299859570908',
                'gid://shopify/Product/9299859701980',
            ])
        );

        $this->assertSame(
            ['9299859570908'],
            ShopifyMetafieldFormatter::extractProductIds('gid://shopify/Product/9299859570908')
        );
    }

    public function test_present_uses_turkish_labels_and_product_kind(): void
    {
        $rows = ShopifyMetafieldFormatter::present([
            [
                'namespace' => 'custom',
                'key' => 'kombin_urunler',
                'type' => 'list.product_reference',
                'value' => '["gid://shopify/Product/111","gid://shopify/Product/222"]',
            ],
            [
                'namespace' => 'custom',
                'key' => 'bakim_talimati',
                'type' => 'rich_text_field',
                'value' => json_encode([
                    'type' => 'root',
                    'children' => [
                        ['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => 'Düşük ısıda yıkayın.']]],
                    ],
                ], JSON_UNESCAPED_UNICODE),
            ],
        ]);

        $this->assertSame('Kombin ürünler', $rows[0]['label']);
        $this->assertSame('products', $rows[0]['kind']);
        $this->assertSame(['111', '222'], array_column($rows[0]['products'], 'id'));
        $this->assertSame('Bakım talimatı', $rows[1]['label']);
        $this->assertSame('richtext', $rows[1]['kind']);
        $this->assertStringContainsString('Düşük ısıda yıkayın.', (string) $rows[1]['html']);

        $together = ShopifyMetafieldFormatter::present([[
            'namespace' => 'custom',
            'key' => 'buy_together_product',
            'type' => 'product_reference',
            'value' => 'gid://shopify/Product/9299859570908',
        ]]);
        $this->assertSame('Birlikte al', $together[0]['label']);
        $this->assertSame(['9299859570908'], array_column($together[0]['products'], 'id'));
    }

    public function test_cdn_width_is_appended_for_shopify_images(): void
    {
        $url = ShopifyMetafieldFormatter::cdnWidth('https://cdn.shopify.com/s/files/1/bere.jpg?v=1', 120);

        $this->assertStringContainsString('width=120', $url);
        $this->assertStringContainsString('v=1', $url);
    }
}
