<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Models\ShopifyOrderItem;
use App\Support\UyumSoftOrderLineFormatter;
use Tests\TestCase;

class UyumSoftOrderLineFormatterTest extends TestCase
{
    public function test_formats_title_with_variant_like_entegra(): void
    {
        $formatter = new UyumSoftOrderLineFormatter(true, true, false);
        $item = new ShopifyOrderItem([
            'product_title' => 'Bluz',
            'variant_title' => 'Siyah / M',
        ]);

        $this->assertSame('Bluz Siyah / M', $formatter->formatItemName($item));
    }

    public function test_includes_barcode_in_extras(): void
    {
        $formatter = new UyumSoftOrderLineFormatter(false, false, true);
        $item = new ShopifyOrderItem([
            'product_title' => 'Bluz',
            'barcode' => '8690001112223',
        ]);

        $extras = $formatter->extras($item);

        $this->assertSame('8690001112223', $extras['barCode']);
        $this->assertSame('Barkod: 8690001112223', $extras['note1']);
    }
}
