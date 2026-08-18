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

class ShipmentYurticiQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_shipment_detail_shows_yurtici_query_panel(): void
    {
        [$user, $shipment] = $this->yurticiShipment();

        $this->actingAs($user)
            ->get(route('shipments.show', $shipment))
            ->assertOk()
            ->assertSee('Yurtiçi Kargo üzerinden kontrol et')
            ->assertSee('2608111150529123')
            ->assertSee('JOB88')
            ->assertSee('Kargo durumunu sorgula');
    }

    public function test_yurtici_verify_and_status_query_shipment(): void
    {
        [$user, $shipment] = $this->yurticiShipment();

        Http::fake([
            '*' => Http::response($this->queryXml('NOP', 'Kargo işlem görmemiş'), 200),
        ]);

        $this->actingAs($user)
            ->postJson(route('shipments.yurtici-verify', $shipment))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('result.out_flag', '0')
            ->assertJsonPath('result.cargo_key', '2608111150529123')
            ->assertJsonPath('result.job_id', 'JOB88')
            ->assertJsonPath('result.operation_status', 'NOP');

        $this->actingAs($user)
            ->postJson(route('shipments.yurtici-status', $shipment))
            ->assertOk()
            ->assertJsonPath('result.operation_status', 'NOP')
            ->assertJsonPath('result.operation_status_label', 'NOP — Kargo işlem görmemiş — kayıt Yurtiçi sistemine ulaştı, şubede henüz barkodlanmadı');

        $this->assertNotNull($shipment->fresh()->provider_payload['last_query'] ?? null);
    }

    public function test_yurtici_tracking_events_combine_event_date_and_time(): void
    {
        [$user, $shipment] = $this->yurticiShipment();

        Http::fake([
            '*' => Http::response($this->queryXmlWithHistory(), 200),
        ]);

        $this->actingAs($user)
            ->postJson(route('shipments.yurtici-status', $shipment))
            ->assertOk()
            ->assertJsonPath('success', true);

        $event = $shipment->trackingEvents()->first();
        $this->assertNotNull($event);
        $this->assertSame('14.08.2026 08:23', optional($event->occurred_at)->format('d.m.Y H:i'));
        $this->assertSame('Kargo Indirildi', $event->description);
    }

    public function test_yurtici_dlv_uses_delivery_event_date_not_document_date(): void
    {
        [$user, $shipment] = $this->yurticiShipment();
        $shipment->update([
            'status' => Shipment::STATUS_SHIPPED,
            'shipped_at' => now()->setDate(2026, 8, 13)->setTime(15, 42),
        ]);

        $shipment->trackingEvents()->create([
            'fingerprint' => 'header-dlv',
            'event_code' => 'DLV',
            'status' => 'DLV',
            'title' => 'DLV',
            'description' => 'Kargo Teslim Edilmiştir',
            'occurred_at' => '2026-08-13 15:42:10',
            'raw' => [
                'cargoKey' => '2608111150529123',
                'documentDate' => '20260813',
                'operationStatus' => 'DLV',
                'operationMessage' => 'Kargo Teslim Edilmiştir',
            ],
        ]);

        Http::fake([
            '*' => Http::response($this->queryXmlDeliveredWithHistory(), 200),
        ]);

        $this->actingAs($user)
            ->postJson(route('shipments.yurtici-status', $shipment))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('result.operation_status', 'DLV');

        $events = $shipment->fresh()->trackingEvents()->orderBy('occurred_at')->get();
        $this->assertCount(2, $events);
        $this->assertSame('13.08.2026 15:42', optional($events[0]->occurred_at)->format('d.m.Y H:i'));
        $this->assertSame('Subeye Teslim Alindi', $events[0]->description);
        $this->assertSame('14.08.2026 11:15', optional($events[1]->occurred_at)->format('d.m.Y H:i'));
        $this->assertSame('Kargo Teslim Edilmiştir', $events[1]->description);

        $this->assertSame(Shipment::STATUS_DELIVERED, $shipment->fresh()->status);
        $this->assertSame('14.08.2026 11:15', optional($shipment->fresh()->delivered_at)->format('d.m.Y H:i'));
        $this->assertSame('delivered', $shipment->order?->fresh()->fulfillment_status);
        $this->assertFalse(
            $events->contains(fn ($event) => ($event->raw['cargoKey'] ?? null) === '2608111150529123')
        );
    }

    public function test_label_includes_job_id_barcode(): void
    {
        [$user, $shipment] = $this->yurticiShipment();

        $this->actingAs($user)
            ->get(route('shipments.print-label', $shipment))
            ->assertOk()
            ->assertSee('2608111150529123')
            ->assertSee('job-barcode-block')
            ->assertSee('print-sheet');
    }

    /**
     * @return array{0: User, 1: Shipment}
     */
    private function yurticiShipment(): array
    {
        $company = CargoCompany::query()->create([
            'name' => 'Yurtiçi Kargo',
            'provider_type' => 'yurtici',
            'is_active' => true,
            'settings' => [
                'sender_username' => 'sender-user',
                'sender_password' => 'sender-pass',
                'default_payment_type' => 'sender',
            ],
        ]);

        $order = ShopifyOrder::query()->create([
            'shopify_order_id' => '3001',
            'order_number' => '#3001',
            'customer_name' => 'Ayşe',
            'customer_email' => 'ayse@example.com',
            'total_price' => 90,
            'currency' => 'TRY',
            'synced_at' => now(),
        ]);

        $shipment = Shipment::query()->create([
            'shopify_order_id' => $order->id,
            'cargo_company_id' => $company->id,
            'order_number' => '#3001',
            'tracking_number' => '2608111150529123',
            'cargo_key' => '2608111150529123',
            'cargo_job_id' => 'JOB88',
            'status' => Shipment::STATUS_PENDING,
            'receiver_name' => 'Ayşe',
        ]);

        $user = User::factory()->create(['email_verified_at' => now()]);

        return [$user, $shipment];
    }

    private function queryXml(string $operationStatus, string $message): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/">
  <soapenv:Body>
    <queryShipmentResponse>
      <ShippingDeliveryResultVO>
        <outFlag>0</outFlag>
        <jobId>JOB88</jobId>
        <shippingDeliveryDetailVO>
          <cargoKey>2608111150529123</cargoKey>
          <operationStatus>{$operationStatus}</operationStatus>
          <operationMessage>{$message}</operationMessage>
        </shippingDeliveryDetailVO>
      </ShippingDeliveryResultVO>
    </queryShipmentResponse>
  </soapenv:Body>
</soapenv:Envelope>
XML;
    }

    private function queryXmlWithHistory(): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/">
  <soapenv:Body>
    <queryShipmentDetailResponse>
      <ShippingDeliveryResultVO>
        <outFlag>0</outFlag>
        <jobId>JOB88</jobId>
        <shippingDeliveryDetailVO>
          <cargoKey>2608111150529123</cargoKey>
          <documentDate>20260813</documentDate>
          <operationStatus>IND</operationStatus>
          <operationMessage>Kargo indirildi</operationMessage>
          <invDocCargoTrxList>
            <invDocCargoVO>
              <unitId>8070</unitId>
              <unitName>AFSIN IRTIBAT</unitName>
              <eventId>YK</eventId>
              <eventName>Kargo Indirildi</eventName>
              <eventDate>20260814</eventDate>
              <eventTime>082304</eventTime>
              <cityName>Kahramanmaras</cityName>
              <townName>Afsin</townName>
            </invDocCargoVO>
          </invDocCargoTrxList>
        </shippingDeliveryDetailVO>
      </ShippingDeliveryResultVO>
    </queryShipmentDetailResponse>
  </soapenv:Body>
</soapenv:Envelope>
XML;
    }

    private function queryXmlDeliveredWithHistory(): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/">
  <soapenv:Body>
    <queryShipmentDetailResponse>
      <ShippingDeliveryResultVO>
        <outFlag>0</outFlag>
        <jobId>JOB88</jobId>
        <shippingDeliveryDetailVO>
          <cargoKey>2608111150529123</cargoKey>
          <documentDate>20260813</documentDate>
          <operationStatus>DLV</operationStatus>
          <operationMessage>Kargo Teslim Edilmiştir</operationMessage>
          <invDocCargoTrxList>
            <invDocCargoVO>
              <unitId>1001</unitId>
              <unitName>MERKEZ SUBE</unitName>
              <eventId>KAB</eventId>
              <eventName>Subeye Teslim Alindi</eventName>
              <eventDate>20260813</eventDate>
              <eventTime>154210</eventTime>
              <documentDate>20260813</documentDate>
            </invDocCargoVO>
            <invDocCargoVO>
              <unitId>2002</unitId>
              <unitName>ALICI ADRESI</unitName>
              <eventId>DLV</eventId>
              <eventName>Kargo Teslim Edilmiştir</eventName>
              <eventDate>20260814</eventDate>
              <eventTime>111530</eventTime>
              <documentDate>20260813</documentDate>
            </invDocCargoVO>
          </invDocCargoTrxList>
        </shippingDeliveryDetailVO>
      </ShippingDeliveryResultVO>
    </queryShipmentDetailResponse>
  </soapenv:Body>
</soapenv:Envelope>
XML;
    }
}
