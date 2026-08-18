<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Models\CargoCompany;
use App\Models\ShopifyOrder;
use App\Models\Shipment;
use App\Support\CargoKeyGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CargoKeyGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_generate_unique_returns_numeric_key(): void
    {
        $key = CargoKeyGenerator::generateUnique();

        $this->assertNotSame('', $key);
        $this->assertMatchesRegularExpression('/^\d+$/', $key);
        $this->assertLessThanOrEqual(CargoKeyGenerator::MAX_LENGTH, strlen($key));
    }

    public function test_generate_unique_appends_shopify_order_number(): void
    {
        $key = CargoKeyGenerator::generateUnique('#1088');

        $this->assertMatchesRegularExpression('/^\d+$/', $key);
        $this->assertStringEndsWith('1088', $key);
        $this->assertLessThanOrEqual(CargoKeyGenerator::MAX_LENGTH, strlen($key));
        $this->assertSame('1088', CargoKeyGenerator::orderDigits('#1088'));
    }

    public function test_sanitize_strips_non_digits(): void
    {
        $this->assertSame('2608121150529123', CargoKeyGenerator::sanitize('YK2608121150529123GEC'));
        $this->assertSame('', CargoKeyGenerator::sanitize('ABC'));
    }

    public function test_generate_unique_avoids_existing_shipment_keys(): void
    {
        $existing = CargoKeyGenerator::generateUnique();

        $company = CargoCompany::query()->create([
            'name' => 'Test Kargo',
            'provider_type' => 'yurtici',
            'is_active' => true,
        ]);

        $order = ShopifyOrder::query()->create([
            'shopify_order_id' => '9001',
            'order_number' => '#9001',
            'customer_name' => 'Test',
            'total_price' => 10,
            'currency' => 'TRY',
            'synced_at' => now(),
        ]);

        Shipment::query()->create([
            'shopify_order_id' => $order->id,
            'cargo_company_id' => $company->id,
            'order_number' => '#1',
            'tracking_number' => $existing,
            'cargo_key' => $existing,
            'status' => Shipment::STATUS_PENDING,
            'receiver_name' => 'Test',
        ]);

        // Force collision candidate — generator should pick another key.
        $another = CargoKeyGenerator::generateUnique();

        $this->assertNotSame($existing, $another);
        $this->assertMatchesRegularExpression('/^\d+$/', $another);
    }
}
