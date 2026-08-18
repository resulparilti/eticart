<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CargoCompany;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SettingsCargoTest extends TestCase
{
    use RefreshDatabase;

    public function test_cargo_settings_page_shows_accordion_with_active_company_first(): void
    {
        $this->seed(\Database\Seeders\CargoCompanySeeder::class);

        CargoCompany::query()->where('provider_type', 'yurtici')->update(['is_active' => true]);
        CargoCompany::query()->where('provider_type', 'aras')->update(['is_active' => false]);

        $user = User::factory()->create(['email_verified_at' => now()]);

        $response = $this->actingAs($user)->get(route('settings.cargo'));

        $response->assertOk()
            ->assertSee('id="cargoCompaniesAccordion"', false)
            ->assertSee('Yurtiçi Kargo')
            ->assertSee('Test')
            ->assertSee('Kargo barkodu')
            ->assertSee('accordion-collapse collapse show', false);
    }

    public function test_cargo_settings_can_be_saved_via_post(): void
    {
        $this->seed(\Database\Seeders\CargoCompanySeeder::class);
        $yurtici = CargoCompany::query()->where('provider_type', 'yurtici')->firstOrFail();
        $aras = CargoCompany::query()->where('provider_type', 'aras')->firstOrFail();
        $mng = CargoCompany::query()->where('provider_type', 'mng')->firstOrFail();
        $ptt = CargoCompany::query()->where('provider_type', 'ptt')->firstOrFail();

        $user = User::factory()->create(['email_verified_at' => now()]);

        $response = $this->actingAs($user)->post(route('settings.cargo.update'), [
            'companies' => [
                [
                    'id' => $yurtici->id,
                    'sender_username' => 'yk-sender',
                    'sender_password' => 'secret-sender',
                    'receiver_username' => 'yk-receiver',
                    'receiver_password' => 'secret-receiver',
                    'customer_code' => '1234567890',
                    'branch_code' => 'BR01',
                    'default_payment_type' => 'sender',
                    'endpoint' => 'http://webservices.yurticikargo.com:8080/KOPSWebServices/ShippingOrderDispatcherServices',
                    'is_active' => '1',
                    'is_default' => '1',
                ],
                ['id' => $aras->id],
                ['id' => $mng->id],
                ['id' => $ptt->id],
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $yurtici->refresh();
        $this->assertTrue($yurtici->is_active);
        $this->assertSame('yk-sender', $yurtici->username);
        $this->assertTrue($yurtici->hasStoredCredential('password'));
        $this->assertSame('secret-sender', $yurtici->readCredential('password'));
        $this->assertSame('yk-receiver', $yurtici->readCredential('api_key'));
        $this->assertSame('secret-receiver', $yurtici->readCredential('api_secret'));
        $this->assertSame('1234567890', $yurtici->settings['customer_code'] ?? null);
        $this->assertArrayNotHasKey('sender_password', $yurtici->settings ?? []);
    }

    public function test_yurtici_shipment_test_returns_api_payload(): void
    {
        $company = CargoCompany::query()->create([
            'name' => 'Yurtiçi Kargo',
            'provider_type' => 'yurtici',
            'username' => 'sender-user',
            'password' => 'sender-pass',
            'is_active' => true,
            'settings' => [
                'sender_username' => 'sender-user',
                'default_payment_type' => 'sender',
                'endpoint' => 'http://webservices.yurticikargo.com:8080/KOPSWebServices/ShippingOrderDispatcherServices',
            ],
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
        <jobId>12345</jobId>
        <shippingOrderDetailVO>
          <cargoKey>YKTEST001</cargoKey>
          <invoiceKey>TEST001</invoiceKey>
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
        <outResult>OK</outResult>
        <shippingDeliveryDetailVO>
          <cargoKey>YKTEST001</cargoKey>
          <operationMessage>Gönderi siparişi alındı</operationMessage>
        </shippingDeliveryDetailVO>
      </ShippingDeliveryResultVO>
    </queryShipmentDetailResponse>
  </soapenv:Body>
</soapenv:Envelope>
XML, 200),
        ]);

        $user = User::factory()->create(['email_verified_at' => now()]);

        $response = $this->actingAs($user)->postJson(route('settings.cargo.test-yurtici-shipment'), [
            'company_id' => $company->id,
            'payment_type' => 'sender',
            'receiver_name' => 'Test Alıcı Ad Soyad',
            'receiver_address' => 'Test Mahallesi Test Sokak No 1 Daire 2',
            'receiver_city' => 'İSTANBUL',
            'receiver_town' => 'KADIKÖY',
            'receiver_phone' => '05551234567',
            'order_number' => 'TEST001',
            'cargo_key' => 'YKTEST001',
            'weight' => 1,
            'cargo_count' => 1,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('result.mode', 'api')
            ->assertJsonPath('result.cargo_key', 'YKTEST001')
            ->assertJsonPath('result.tracking_ready', false);
    }

    public function test_yurtici_query_returns_tracking_when_doc_id_present(): void
    {
        $company = CargoCompany::query()->create([
            'name' => 'Yurtiçi Kargo',
            'provider_type' => 'yurtici',
            'username' => 'sender-user',
            'password' => 'sender-pass',
            'is_active' => true,
            'settings' => [
                'sender_username' => 'sender-user',
                'default_payment_type' => 'sender',
                'endpoint' => 'http://webservices.yurticikargo.com:8080/KOPSWebServices/ShippingOrderDispatcherServices',
            ],
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
          <cargoKey>YKTEST001</cargoKey>
          <docId>123456789012</docId>
          <trackingUrl>https://www.yurticikargo.com/tr/online-servisler/gonderi-sorgula?code=123456789012</trackingUrl>
          <operationMessage>Şubede kabul edildi</operationMessage>
        </shippingDeliveryDetailVO>
      </ShippingDeliveryResultVO>
    </queryShipmentDetailResponse>
  </soapenv:Body>
</soapenv:Envelope>
XML, 200),
        ]);

        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)
            ->postJson(route('settings.cargo.query-yurtici-shipment'), [
                'company_id' => $company->id,
                'payment_type' => 'sender',
                'key' => 'YKTEST001',
                'key_type' => 0,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('result.tracking_ready', true)
            ->assertJsonPath('result.tracking_number', '123456789012')
            ->assertJsonPath('result.doc_id', '123456789012');
    }

    public function test_yurtici_label_page_renders_cargo_key_barcode(): void
    {
        $company = CargoCompany::query()->create([
            'name' => 'Yurtiçi Kargo',
            'provider_type' => 'yurtici',
            'is_active' => true,
            'settings' => [
                'customer_code' => '1212892093',
            ],
        ]);

        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)
            ->get(route('settings.cargo.yurtici-label', [
                'company_id' => $company->id,
                'cargo_key' => '2608121150529001',
                'invoice_key' => 'INV001',
                'job_id' => 'JOB99',
                'receiver_name' => 'Test Alıcı Ad Soyad',
                'receiver_address' => 'Test Mahallesi Test Sokak No 1',
                'receiver_city' => 'İSTANBUL',
                'receiver_town' => 'KADIKÖY',
                'receiver_phone' => '05551234567',
            ]))
            ->assertOk()
            ->assertSee('2608121150529001')
            ->assertSee('job-barcode-block')
            ->assertSee('print-sheet')
            ->assertSee('TESLİMAT ADRESİ')
            ->assertSee('TEST ALICI AD SOYAD')
            ->assertSee('Gönderen:')
            ->assertSee('İade ve Değişim:')
            ->assertSee('JsBarcode', false);
    }
}
