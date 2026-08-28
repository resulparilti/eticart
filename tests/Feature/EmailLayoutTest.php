<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\GenericMail;
use App\Mail\ShipmentInvoiceMail;
use App\Models\Setting;
use App\Models\Shipment;
use App\Models\ShopifyOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailLayoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::setValue('general_company_name', "O'renne", 'general');
        Setting::setValue('general_website_url', 'https://orenne.com', 'general');
        Setting::setValue('mail_header_bg', '#000000', 'mail');
        Setting::setValue('mail_button_bg', '#000000', 'mail');
        Setting::setValue('mail_link_color', '#c45c26', 'mail');
    }

    public function test_generic_mail_uses_centered_logo_fallback_and_footer_links(): void
    {
        $html = (new GenericMail([
            'subject' => 'Siparişiniz Alındı - #1004',
            'title' => 'Siparişiniz Alındı - #1004',
            'body' => 'Merhaba Resul Parıltı, siparişiniz alındı.',
        ]))->render();

        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $this->assertStringContainsString("O'renne", $html);
        $this->assertStringContainsString('<!--[if mso]>', $html);
        $this->assertStringContainsString('Siparişiniz Alındı - #1004', $html);
        $this->assertStringContainsString('Mağazamız', $html);
        $this->assertStringContainsString('Hesabım', $html);
        $this->assertStringContainsString('align="center"', $html);
        $this->assertStringContainsString('xmlns:v="urn:schemas-microsoft-com:vml"', $html);
    }

    public function test_shipment_invoice_mail_keeps_outlook_safe_buttons(): void
    {
        $order = ShopifyOrder::query()->create([
            'shopify_order_id' => '1004',
            'order_number' => '#1004',
            'customer_name' => 'Resul Parıltı',
            'customer_email' => 'resul@example.com',
            'total_price' => 1,
            'currency' => 'TRY',
            'payment_status' => 'paid',
            'fulfillment_status' => 'fulfilled',
            'synced_at' => now(),
        ]);

        $shipment = Shipment::query()->create([
            'shopify_order_id' => $order->id,
            'order_number' => '#1004',
            'tracking_number' => 'YK123',
            'tracking_url' => 'https://www.yurticikargo.com/tr/online-servisler/gonderi-sorgula?code=YK123',
            'status' => Shipment::STATUS_SHIPPED,
            'receiver_name' => 'Resul Parıltı',
            'receiver_phone' => '555',
            'receiver_address' => 'Adres',
            'receiver_city' => 'İstanbul',
            'shipped_at' => now(),
        ]);

        $html = (new ShipmentInvoiceMail($order, $shipment, [
            'subject' => 'Siparişiniz kargoya verildi - #1004',
            'status_text' => 'Kargoda',
            'tracking_url' => $shipment->tracking_url,
            'invoice_url' => 'https://neotic.com.tr/invoices/abc',
            'company_name' => 'Yurtiçi Kargo',
            'brand' => [
                'name' => "O'renne",
                'header_bg' => '#000000',
                'header_text' => '#ffffff',
                'body_text' => '#142433',
                'muted_text' => '#5b6b7c',
                'link' => '#c45c26',
                'button_bg' => '#000000',
                'button_text' => '#ffffff',
                'logo_url' => null,
                'site_url' => 'https://orenne.com',
                'account_url' => 'https://orenne.com/account',
            ],
        ]))->render();

        $this->assertStringContainsString('Siparişiniz kargoya verildi', $html);
        $this->assertStringContainsString('Kargo bilgisi', $html);
        $this->assertStringContainsString('Kargoyu takip et', $html);
        $this->assertStringContainsString('Faturayı indir', $html);
        $this->assertStringContainsString('v:roundrect', $html);
        $this->assertStringContainsString('mso-hide:all', $html);
        $this->assertStringContainsString('#c45c26', $html);
        $this->assertStringContainsString('Mağazamız', $html);
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $this->assertStringContainsString("O'renne", $html);
    }
}
