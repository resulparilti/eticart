<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminNotification;
use App\Models\CargoCompany;
use App\Models\Setting;
use App\Models\Shipment;
use App\Models\ShopifyOrder;
use App\Models\User;
use App\Services\OrderSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OrderAutomationTest extends TestCase
{
    use RefreshDatabase;

    public function test_sending_to_cargo_marks_order_preparing(): void
    {
        $company = $this->yurticiCompany();
        $order = $this->order();
        $user = User::factory()->create(['email_verified_at' => now()]);

        Http::fake([
            '*' => Http::sequence()
                ->push($this->soapCreate('YKAUTO1001'), 200)
                ->push($this->soapQuery('YKAUTO1001', 'Gönderi siparişi alındı'), 200),
        ]);

        $this->actingAs($user)
            ->post(route('orders.assign-cargo', $order), [
                'cargo_company_id' => $company->id,
                'payment_type' => 'sender',
                'weight' => 1,
            ])
            ->assertRedirect();

        $order->refresh();
        $shipment = Shipment::query()->where('shopify_order_id', $order->id)->first();

        $this->assertSame('preparing', $order->fulfillment_status);
        $this->assertNotNull($shipment);
        $this->assertSame(Shipment::STATUS_PENDING, $shipment->status);
        $this->assertDatabaseHas('admin_notifications', [
            'type' => AdminNotification::TYPE_ORDER_PREPARING,
            'subject_id' => $order->id,
        ]);
    }

    public function test_branch_accept_marks_order_shipped(): void
    {
        $company = $this->yurticiCompany();
        $order = $this->order(['fulfillment_status' => 'preparing']);
        $shipment = Shipment::query()->create([
            'shopify_order_id' => $order->id,
            'cargo_company_id' => $company->id,
            'order_number' => $order->order_number,
            'tracking_number' => 'YKWAIT01',
            'status' => Shipment::STATUS_PENDING,
            'receiver_name' => $order->customer_name,
        ]);

        Http::fake([
            '*' => Http::response($this->soapQuery('123456789012', 'Şubeye teslim alındı', '123456789012'), 200),
        ]);

        $result = app(\App\Services\CargoService::class)->updateTrackingStatus();

        $this->assertGreaterThan(0, $result['updated']);
        $this->assertSame('fulfilled', $order->fresh()->fulfillment_status);
        $this->assertSame(Shipment::STATUS_SHIPPED, $shipment->fresh()->status);
        $this->assertDatabaseHas('admin_notifications', [
            'type' => AdminNotification::TYPE_ORDER_SHIPPED,
            'subject_id' => $order->id,
        ]);
    }

    public function test_yurtici_dlv_marks_shipment_and_order_delivered(): void
    {
        $company = $this->yurticiCompany();
        $order = $this->order(['fulfillment_status' => 'fulfilled']);
        $shipment = Shipment::query()->create([
            'shopify_order_id' => $order->id,
            'cargo_company_id' => $company->id,
            'order_number' => $order->order_number,
            'tracking_number' => '123456789012',
            'cargo_key' => 'YKWAIT01',
            'status' => Shipment::STATUS_SHIPPED,
            'receiver_name' => $order->customer_name,
            'shipped_at' => now()->subDay(),
        ]);

        Http::fake([
            '*' => Http::response($this->soapQuery('YKWAIT01', 'Teslim edildi', '123456789012', 'DLV'), 200),
        ]);

        $result = app(\App\Services\CargoService::class)->updateTrackingStatus();

        $this->assertGreaterThan(0, $result['updated']);
        $this->assertSame(Shipment::STATUS_DELIVERED, $shipment->fresh()->status);
        $this->assertSame('delivered', $order->fresh()->fulfillment_status);
        $this->assertDatabaseHas('admin_notifications', [
            'type' => AdminNotification::TYPE_ORDER_DELIVERED,
            'subject_id' => $order->id,
        ]);
    }

    public function test_order_sync_preserves_preparing_when_shopify_still_unfulfilled(): void
    {
        Setting::setValue('shopify_store_url', 'https://test-shop.myshopify.com', 'shopify');
        Setting::setValue('shopify_access_token', 'shpat_test', 'shopify');

        $order = $this->order([
            'shopify_order_id' => '555',
            'fulfillment_status' => 'preparing',
        ]);

        Http::fake([
            '*' => Http::response([
                'orders' => [[
                    'id' => 555,
                    'name' => $order->order_number,
                    'email' => 'ali@example.com',
                    'financial_status' => 'paid',
                    'fulfillment_status' => 'unfulfilled',
                    'total_price' => '150.00',
                    'currency' => 'TRY',
                    'line_items' => [],
                    'customer' => [
                        'id' => 9,
                        'first_name' => 'Ali',
                        'last_name' => 'Veli',
                        'email' => 'ali@example.com',
                    ],
                    'shipping_address' => [
                        'name' => 'Ali Veli',
                        'address1' => 'Test Cad.',
                        'city' => 'İstanbul',
                    ],
                ]],
            ], 200),
        ]);

        app(OrderSyncService::class)->sync(10, 'any');

        $fresh = $order->fresh();
        $this->assertSame('preparing', $fresh->fulfillment_status);
        $this->assertSame('Test Alıcı Ad Soyad', $fresh->customer_name);
    }

    public function test_orders_index_shows_preparing_label(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $this->order(['fulfillment_status' => 'preparing', 'order_number' => '#PREP1']);

        $this->actingAs($user)
            ->get(route('orders.index'))
            ->assertOk()
            ->assertSee('Hazırlanıyor');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function order(array $overrides = []): ShopifyOrder
    {
        return ShopifyOrder::query()->create(array_merge([
            'shopify_order_id' => (string) fake()->unique()->numerify('20###'),
            'order_number' => '#'.fake()->unique()->numerify('20##'),
            'customer_name' => 'Test Alıcı Ad Soyad',
            'customer_email' => 'ali@example.com',
            'customer_phone' => '05551234567',
            'shipping_address' => 'Test Mahallesi Test Sokak No 1, 34710, İstanbul, Turkey',
            'shipping_city' => 'Kadıköy',
            'total_price' => 150,
            'currency' => 'TRY',
            'payment_status' => 'paid',
            'fulfillment_status' => 'unfulfilled',
            'synced_at' => now(),
        ], $overrides));
    }

    private function yurticiCompany(): CargoCompany
    {
        return CargoCompany::query()->create([
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
    }

    private function soapCreate(string $cargoKey): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/">
  <soapenv:Body>
    <createShipmentResponse>
      <ShippingOrderResultVO>
        <outFlag>0</outFlag>
        <outResult>Başarılı</outResult>
        <jobId>1</jobId>
        <shippingOrderDetailVO>
          <cargoKey>{$cargoKey}</cargoKey>
        </shippingOrderDetailVO>
      </ShippingOrderResultVO>
    </createShipmentResponse>
  </soapenv:Body>
</soapenv:Envelope>
XML;
    }

    private function soapQuery(string $key, string $message, ?string $docId = null, ?string $operationStatus = null): string
    {
        $doc = $docId ? "<docId>{$docId}</docId>" : '';
        $op = $operationStatus ? "<operationStatus>{$operationStatus}</operationStatus>" : '';

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/">
  <soapenv:Body>
    <queryShipmentDetailResponse>
      <ShippingDeliveryResultVO>
        <outFlag>0</outFlag>
        <shippingDeliveryDetailVO>
          <cargoKey>{$key}</cargoKey>
          {$doc}
          {$op}
          <operationMessage>{$message}</operationMessage>
        </shippingDeliveryDetailVO>
      </ShippingDeliveryResultVO>
    </queryShipmentDetailResponse>
  </soapenv:Body>
</soapenv:Envelope>
XML;
    }
}
