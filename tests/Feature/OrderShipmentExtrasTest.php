<?php

namespace Tests\Feature;

use App\Models\CargoCompany;
use App\Models\Shipment;
use App\Models\ShopifyOrder;
use App\Models\User;
use App\Services\CargoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OrderShipmentExtrasTest extends TestCase
{
    use RefreshDatabase;

    public function test_orders_index_shows_turkish_status_and_relative_cargo_logo(): void
    {
        $user = $this->user();
        $company = $this->yurticiCompany(false);
        $order = $this->order(['fulfillment_status' => 'unfulfilled', 'payment_status' => 'paid']);

        Shipment::query()->create([
            'shopify_order_id' => $order->id,
            'cargo_company_id' => $company->id,
            'order_number' => $order->order_number,
            'tracking_number' => 'YKTESTLOGO',
            'status' => Shipment::STATUS_SHIPPED,
            'receiver_name' => $order->customer_name,
            'shipped_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('orders.index'))
            ->assertOk()
            ->assertSee('/images/cargo/yurtici.svg', false)
            ->assertSee('Karşılanmadı')
            ->assertSee('Ödendi')
            ->assertDontSee('>Fulfillment<', false)
            ->assertDontSee('>unfulfilled<', false);
    }

    public function test_local_shipment_can_be_cancelled_from_order_and_shipments_pages(): void
    {
        $user = $this->user();
        $company = $this->yurticiCompany(false);
        $order = $this->order();
        $shipment = Shipment::query()->create([
            'shopify_order_id' => $order->id,
            'cargo_company_id' => $company->id,
            'order_number' => $order->order_number,
            'tracking_number' => 'YKLOCAL1',
            'status' => Shipment::STATUS_SHIPPED,
            'receiver_name' => $order->customer_name,
            'notes' => '[local]',
        ]);

        $this->actingAs($user)
            ->post(route('orders.shipments.cancel', [$order, $shipment]))
            ->assertRedirect(route('orders.show', $order));

        $this->assertSoftDeleted('shipments', ['id' => $shipment->id]);
    }

    public function test_yurtici_cancel_is_rejected_when_accepted_at_branch(): void
    {
        $company = $this->yurticiCompany(true);
        $order = $this->order();
        $shipment = Shipment::query()->create([
            'shopify_order_id' => $order->id,
            'cargo_company_id' => $company->id,
            'order_number' => $order->order_number,
            'tracking_number' => 'YKBRANCH1',
            'status' => Shipment::STATUS_SHIPPED,
            'receiver_name' => $order->customer_name,
            'notes' => '[api]',
        ]);

        Http::fake([
            '*' => Http::response(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/">
  <soapenv:Body>
    <queryShipmentDetailResponse>
      <ShippingDeliveryResultVO>
        <outFlag>0</outFlag>
        <shippingDeliveryDetailVO>
          <cargoKey>YKBRANCH1</cargoKey>
          <docId>99887766</docId>
          <operationMessage>Şubede kabul edildi</operationMessage>
        </shippingDeliveryDetailVO>
      </ShippingDeliveryResultVO>
    </queryShipmentDetailResponse>
  </soapenv:Body>
</soapenv:Envelope>
XML, 200),
        ]);

        $this->expectException(\App\Exceptions\CargoException::class);
        $this->expectExceptionMessage('şubeye teslim');

        app(CargoService::class)->cancelShipment($shipment);

        $this->assertSame(Shipment::STATUS_SHIPPED, $shipment->fresh()->status);
    }

    public function test_yurtici_cancel_calls_api_when_not_at_branch(): void
    {
        $company = $this->yurticiCompany(true);
        $order = $this->order();
        $shipment = Shipment::query()->create([
            'shopify_order_id' => $order->id,
            'cargo_company_id' => $company->id,
            'order_number' => $order->order_number,
            'tracking_number' => 'YKCANCEL1',
            'status' => Shipment::STATUS_SHIPPED,
            'receiver_name' => $order->customer_name,
            'notes' => '[api]',
        ]);

        Http::fake([
            '*' => Http::sequence()
                ->push(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/">
  <soapenv:Body>
    <queryShipmentDetailResponse>
      <ShippingDeliveryResultVO>
        <outFlag>0</outFlag>
        <shippingDeliveryDetailVO>
          <cargoKey>YKCANCEL1</cargoKey>
          <operationMessage>Gönderi siparişi alındı</operationMessage>
        </shippingDeliveryDetailVO>
      </ShippingDeliveryResultVO>
    </queryShipmentDetailResponse>
  </soapenv:Body>
</soapenv:Envelope>
XML, 200)
                ->push(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/">
  <soapenv:Body>
    <cancelShipmentResponse>
      <ShippingOrderResultVO>
        <outFlag>0</outFlag>
        <outResult>Başarılı</outResult>
        <jobId>1</jobId>
      </ShippingOrderResultVO>
    </cancelShipmentResponse>
  </soapenv:Body>
</soapenv:Envelope>
XML, 200),
        ]);

        app(CargoService::class)->cancelShipment($shipment);

        $this->assertSoftDeleted('shipments', ['id' => $shipment->id]);
    }

    public function test_order_detail_sends_cargo_via_api_like_list_page(): void
    {
        $user = $this->user();
        $ready = $this->yurticiCompany(true);
        $notReady = CargoCompany::query()->create([
            'name' => 'Tanımsız Aras',
            'provider_type' => 'aras',
            'is_active' => true,
        ]);
        $order = $this->order([
            'customer_name' => 'Test Alıcı Ad Soyad',
            'customer_phone' => '05551234567',
            'shipping_address' => 'Test Mahallesi Test Sokak No 1 Daire 2, 34710, İstanbul, Turkey',
            'shipping_city' => 'Kadıköy',
        ]);

        $this->actingAs($user)
            ->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee('Kargo Servisine Gönder')
            ->assertSee($ready->name)
            ->assertDontSee($notReady->name);

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
        <jobId>77</jobId>
        <shippingOrderDetailVO>
          <cargoKey>YKDETAIL1001</cargoKey>
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
          <cargoKey>YKDETAIL1001</cargoKey>
          <operationMessage>Gönderi siparişi alındı</operationMessage>
        </shippingDeliveryDetailVO>
      </ShippingDeliveryResultVO>
    </queryShipmentDetailResponse>
  </soapenv:Body>
</soapenv:Envelope>
XML, 200),
        ]);

        $this->actingAs($user)
            ->post(route('orders.assign-cargo', $order), [
                'cargo_company_id' => $ready->id,
                'payment_type' => 'sender',
                'weight' => 1,
            ])
            ->assertRedirect(route('orders.show', $order));

        $shipment = Shipment::query()->where('shopify_order_id', $order->id)->first();
        $this->assertNotNull($shipment);
        $this->assertSame($ready->id, $shipment->cargo_company_id);
        $this->assertNotEmpty($shipment->tracking_number);
        $this->assertMatchesRegularExpression('/^\d+$/', (string) $shipment->tracking_number);
        $this->assertStringStartsWith('[api]', ltrim((string) $shipment->notes));
    }

    public function test_cancelled_shipments_are_removed_from_order_detail(): void
    {
        $user = $this->user();
        $company = $this->yurticiCompany(false);
        $order = $this->order();
        $shipment = Shipment::query()->create([
            'shopify_order_id' => $order->id,
            'cargo_company_id' => $company->id,
            'order_number' => $order->order_number,
            'tracking_number' => 'YKOLD1',
            'status' => Shipment::STATUS_CANCELLED,
            'receiver_name' => $order->customer_name,
        ]);

        $this->actingAs($user)
            ->get(route('orders.show', $order))
            ->assertOk()
            ->assertDontSee('YKOLD1');

        $this->assertSoftDeleted('shipments', ['id' => $shipment->id]);
    }

    public function test_invoice_can_be_uploaded_and_url_is_shown(): void
    {
        Storage::fake('public');
        Http::fake();
        $user = $this->user();
        $order = $this->order();

        $this->actingAs($user)
            ->post(route('orders.invoice.upload', $order), [
                'invoice' => UploadedFile::fake()->create('fatura.pdf', 120, 'application/pdf'),
            ])
            ->assertRedirect(route('orders.show', $order));

        $order->refresh();
        $this->assertTrue($order->hasInvoice());
        $this->assertNotNull($order->invoice_token);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{48}$/', (string) $order->invoice_token);
        $this->assertSame(route('invoices.public', $order->invoice_token), $order->invoiceUrl());
        $this->assertStringNotContainsString('/orders/'.$order->id.'/invoice', (string) $order->invoiceUrl());

        $this->actingAs($user)
            ->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee($order->invoiceUrl(), false)
            ->assertSee('fatura.pdf')
            ->assertSee('Henüz gönderilmedi');

        $this->get(route('invoices.public', $order->invoice_token))->assertOk();
        $this->get('/f/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa')->assertNotFound();
    }

    public function test_shipment_invoice_mail_requires_cargo_and_invoice(): void
    {
        $user = $this->user();
        $order = $this->order();

        $this->actingAs($user)
            ->post(route('orders.send-shipment-mail', $order))
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_shipment_invoice_mail_is_sent_with_download_link(): void
    {
        Storage::fake('public');
        Mail::fake();

        $user = $this->user();
        $company = $this->yurticiCompany(false);
        $order = $this->order();
        Shipment::query()->create([
            'shopify_order_id' => $order->id,
            'cargo_company_id' => $company->id,
            'order_number' => $order->order_number,
            'tracking_number' => 'YKMAIL1',
            'tracking_url' => 'https://www.yurticikargo.com/tr/online-servisler/gonderi-sorgula?code=YKMAIL1',
            'status' => Shipment::STATUS_SHIPPED,
            'receiver_name' => $order->customer_name,
            'shipped_at' => now(),
        ]);

        $path = UploadedFile::fake()->create('fatura.pdf', 80, 'application/pdf')
            ->storeAs('order-invoices/'.$order->id, 'fatura.pdf', 'public');

        $order->update([
            'invoice_path' => $path,
            'invoice_original_name' => 'fatura.pdf',
            'invoice_uploaded_at' => now(),
        ]);

        $this->actingAs($user)
            ->post(route('orders.send-shipment-mail', $order))
            ->assertRedirect()
            ->assertSessionHas('success');

        Mail::assertSent(\App\Mail\ShipmentInvoiceMail::class, function ($mail) use ($order): bool {
            $order->refresh();

            return count($mail->attachments()) === 0
                && (string) ($mail->payload['invoice_url'] ?? '') === (string) $order->invoiceUrl()
                && str_contains((string) ($mail->payload['tracking_url'] ?? ''), 'YKMAIL1');
        });

        $this->actingAs($user)
            ->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee('SMTP teslim')
            ->assertSee('Tekrar gönder');
    }

    private function user(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function order(array $overrides = []): ShopifyOrder
    {
        return ShopifyOrder::query()->create(array_merge([
            'shopify_order_id' => (string) fake()->unique()->numerify('10###'),
            'order_number' => '#'.fake()->unique()->numerify('10##'),
            'customer_name' => 'Ali Veli',
            'customer_email' => 'ali@example.com',
            'total_price' => 150,
            'currency' => 'TRY',
            'payment_status' => 'paid',
            'fulfillment_status' => 'unfulfilled',
            'shopify_created_at' => now(),
            'synced_at' => now(),
        ], $overrides));
    }

    private function yurticiCompany(bool $configured): CargoCompany
    {
        return CargoCompany::query()->create([
            'name' => 'Yurtiçi Kargo',
            'provider_type' => 'yurtici',
            'is_active' => true,
            'settings' => $configured ? [
                'sender_username' => 'sender-user',
                'sender_password' => 'sender-pass',
                'default_payment_type' => 'sender',
                'endpoint' => 'http://webservices.yurticikargo.com:8080/KOPSWebServices/ShippingOrderDispatcherServices',
            ] : [],
        ]);
    }
}
