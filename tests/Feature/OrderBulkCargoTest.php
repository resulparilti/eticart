<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CargoCompany;
use App\Models\Shipment;
use App\Models\ShopifyOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OrderBulkCargoTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_send_cargo_creates_yurtici_shipment_and_shows_on_index(): void
    {
        $company = CargoCompany::query()->create([
            'name' => 'Yurtiçi Kargo',
            'provider_type' => 'yurtici',
            'is_active' => true,
            'is_default' => true,
            'settings' => [
                'sender_username' => 'sender-user',
                'sender_password' => 'sender-pass',
                'default_payment_type' => 'sender',
                'endpoint' => 'http://webservices.yurticikargo.com:8080/KOPSWebServices/ShippingOrderDispatcherServices',
            ],
        ]);

        $order = ShopifyOrder::query()->create([
            'shopify_order_id' => '1001',
            'order_number' => '#1001',
            'customer_name' => 'Test Alıcı Ad Soyad',
            'customer_email' => 'test@example.com',
            'customer_phone' => '05551234567',
            'shipping_address' => 'Test Mahallesi Test Sokak No 1 Daire 2, 34710, İstanbul, Turkey',
            'shipping_city' => 'Kadıköy',
            'total_price' => 250,
            'currency' => 'TRY',
            'payment_status' => 'paid',
            'fulfillment_status' => 'unfulfilled',
            'shopify_created_at' => now(),
            'synced_at' => now(),
        ]);

        Http::fake([
            '*' => Http::sequence()
                ->push(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/">
  <soapenv:Body>
    <createShipmentResponse>
      <ShippingOrderResultVO>
        <outFlag>0</outFlag>
        <outResult>Başarılı</outResult>
        <jobId>999</jobId>
        <shippingOrderDetailVO>
          <cargoKey>YKORDER1001</cargoKey>
        </shippingOrderDetailVO>
      </ShippingOrderResultVO>
    </createShipmentResponse>
  </soapenv:Body>
</soapenv:Envelope>
XML, 200)
                ->push(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/">
  <soapenv:Body>
    <queryShipmentDetailResponse>
      <ShippingDeliveryResultVO>
        <outFlag>0</outFlag>
        <shippingDeliveryDetailVO>
          <cargoKey>YKORDER1001</cargoKey>
          <operationMessage>Gönderi siparişi alındı</operationMessage>
        </shippingDeliveryDetailVO>
      </ShippingDeliveryResultVO>
    </queryShipmentDetailResponse>
  </soapenv:Body>
</soapenv:Envelope>
XML, 200),
        ]);

        $user = User::factory()->create(['email_verified_at' => now()]);

        $response = $this->actingAs($user)
            ->postJson(route('orders.bulk-send-cargo'), [
                'order_ids' => [$order->id],
                'cargo_company_id' => $company->id,
                'payment_type' => 'sender',
            ])
            ->assertOk()
            ->assertJsonPath('summary.success', 1);

        $tracking = (string) $response->json('results.success.0.tracking_number');
        $this->assertNotSame('', $tracking);
        $this->assertMatchesRegularExpression('/^\d+$/', $tracking);

        $this->assertDatabaseHas('shipments', [
            'shopify_order_id' => $order->id,
            'cargo_company_id' => $company->id,
            'tracking_number' => $tracking,
        ]);

        $this->actingAs($user)
            ->get(route('orders.index'))
            ->assertOk()
            ->assertSee('images/cargo/yurtici.svg', false)
            ->assertSee('order-select-checkbox', false);
    }

    public function test_bulk_print_labels_opens_for_shipped_orders(): void
    {
        $company = CargoCompany::query()->create([
            'name' => 'Yurtiçi Kargo',
            'provider_type' => 'yurtici',
            'is_active' => true,
        ]);

        $order = ShopifyOrder::query()->create([
            'shopify_order_id' => '1002',
            'order_number' => '#1002',
            'customer_name' => 'Test Alıcı Ad Soyad',
            'customer_phone' => '05551234567',
            'shipping_address' => 'Test Mahallesi Test Sokak No 1',
            'shipping_city' => 'Kadıköy',
            'total_price' => 100,
            'currency' => 'TRY',
            'synced_at' => now(),
        ]);

        Shipment::query()->create([
            'shopify_order_id' => $order->id,
            'cargo_company_id' => $company->id,
            'order_number' => $order->order_number,
            'tracking_number' => '2608121150529001',
            'cargo_key' => '2608121150529001',
            'status' => Shipment::STATUS_SHIPPED,
            'receiver_name' => $order->customer_name,
            'receiver_phone' => $order->customer_phone,
            'receiver_address' => $order->shipping_address,
            'receiver_city' => 'İSTANBUL',
            'shipped_at' => now(),
        ]);

        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)
            ->post(route('orders.bulk-print-labels'), [
                'order_ids' => [$order->id],
            ])
            ->assertOk()
            ->assertSee('2608121150529001')
            ->assertSee('print-sheet')
            ->assertSee('TESLİMAT ADRESİ')
            ->assertSee('Gönderen:')
            ->assertSee('İade ve Değişim:');
    }

    public function test_order_print_label_uses_general_settings_footer(): void
    {
        \App\Models\Setting::setValue('general_company_name', 'Özşeyma Tekstil', 'general', 'Firma Adı');
        \App\Models\Setting::setValue('general_company_address', 'Test Mah. No 1 İstanbul', 'general', 'Firma Adresi');
        \App\Models\Setting::setValue('general_company_phone', '0212 000 00 00', 'general', 'Firma Telefonu');
        \App\Models\Setting::setValue('general_return_cargo_name', 'Yurtiçi Kargo', 'general', 'İade Kargo Firması');
        \App\Models\Setting::setValue('general_return_cargo_code', '216625941', 'general', 'İade Kargo Numarası');

        $company = CargoCompany::query()->create([
            'name' => 'Yurtiçi Kargo',
            'provider_type' => 'yurtici',
            'is_active' => true,
        ]);

        $order = ShopifyOrder::query()->create([
            'shopify_order_id' => '1003',
            'order_number' => '#1003',
            'customer_name' => 'Ayşe Yılmaz',
            'customer_phone' => '05551112233',
            'shipping_address' => 'Bahçelievler Mah. Gül Sok. No 4',
            'shipping_city' => 'Üsküdar',
            'total_price' => 80,
            'currency' => 'TRY',
            'synced_at' => now(),
        ]);

        Shipment::query()->create([
            'shopify_order_id' => $order->id,
            'cargo_company_id' => $company->id,
            'order_number' => $order->order_number,
            'tracking_number' => 'EXUMA_4984',
            'status' => Shipment::STATUS_SHIPPED,
            'receiver_name' => $order->customer_name,
            'receiver_phone' => $order->customer_phone,
            'receiver_address' => $order->shipping_address,
            'receiver_city' => 'İSTANBUL',
            'shipped_at' => now(),
        ]);

        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)
            ->get(route('orders.index'))
            ->assertOk()
            ->assertSee('Barkod Yazdır');

        $this->actingAs($user)
            ->get(route('orders.print-label', $order))
            ->assertOk()
            ->assertSee('TESLİMAT ADRESİ')
            ->assertSee('AYŞE YILMAZ')
            ->assertSee('EXUMA_4984')
            ->assertSee('Özşeyma Tekstil')
            ->assertSee('Yurtiçi Kargo 216625941')
            ->assertSee('CODE128');
    }
}
