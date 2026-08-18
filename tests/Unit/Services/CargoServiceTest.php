<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\CargoCompany;
use App\Services\Cargo\ArasCargoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CargoServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_mode_creates_tracking_number(): void
    {
        $company = CargoCompany::query()->create([
            'name' => 'Aras',
            'provider_type' => 'aras',
            'is_active' => true,
            'api_key' => null,
            'username' => null,
            'password' => null,
            'api_secret' => null,
        ]);

        $service = new ArasCargoService($company);
        $result = $service->createShipment([
            'order_number' => '1001',
            'receiver_name' => 'Test',
            'receiver_phone' => '555',
            'receiver_address' => 'Adres',
            'receiver_city' => 'İstanbul',
            'weight' => 1,
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('local', $result['mode']);
        $this->assertNotEmpty($result['tracking_number']);
        $this->assertStringStartsWith('ARAS-', $result['tracking_number']);
    }

    public function test_local_tracking_info(): void
    {
        $company = CargoCompany::query()->create([
            'name' => 'Aras',
            'provider_type' => 'aras',
            'is_active' => true,
        ]);

        $info = (new ArasCargoService($company))->getTrackingInfo('ARAS-TEST');

        $this->assertSame('ARAS-TEST', $info['tracking_number']);
        $this->assertSame('shipped', $info['status']);
    }
}
