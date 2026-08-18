<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\ShopifyOrder;
use Tests\TestCase;

class ShopifyOrderInvoiceNoteTest extends TestCase
{
    public function test_strip_invoice_lines_removes_fatura_address(): void
    {
        $notes = "Müşteri notu\nFatura: https://panel.example.com/invoices/abc\nTeşekkürler";

        $this->assertSame(
            "Müşteri notu\n\nTeşekkürler",
            ShopifyOrder::stripInvoiceLines($notes)
        );
    }

    public function test_strip_invoice_lines_handles_leading_spaces_and_empty_result(): void
    {
        $this->assertNull(ShopifyOrder::stripInvoiceLines("  Fatura: https://old.example/invoice\r\n"));
        $this->assertFalse(ShopifyOrder::noteContainsInvoiceLine('Müşteri arayacak'));
    }

    public function test_append_invoice_line_does_not_duplicate(): void
    {
        $notes = ShopifyOrder::appendInvoiceLine('Sipariş notu', 'https://a.example/inv');
        $notes = ShopifyOrder::appendInvoiceLine($notes, 'https://b.example/inv');

        $this->assertSame("Sipariş notu\n\nFatura: https://b.example/inv", $notes);
        $this->assertTrue(ShopifyOrder::noteContainsInvoiceLine($notes));
    }
}
