<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CargoCompany;
use App\Models\Shipment;
use App\Models\ShopifyOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShipmentControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_shipments_index_requires_auth(): void
    {
        $this->get(route('shipments.index'))->assertRedirect('/login');
    }

    public function test_shipments_index_renders(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $company = CargoCompany::query()->create([
            'name' => 'Aras',
            'provider_type' => 'aras',
            'is_active' => true,
        ]);
        $order = ShopifyOrder::query()->create([
            'shopify_order_id' => '2001',
            'order_number' => '#2001',
            'customer_name' => 'Veli',
            'customer_email' => 'veli@example.com',
            'total_price' => 120,
            'currency' => 'TRY',
            'payment_status' => 'paid',
            'fulfillment_status' => 'unfulfilled',
            'shopify_created_at' => now(),
            'synced_at' => now(),
        ]);

        Shipment::query()->create([
            'shopify_order_id' => $order->id,
            'cargo_company_id' => $company->id,
            'order_number' => '#2001',
            'tracking_number' => 'ARAS-ABC',
            'status' => 'pending',
            'receiver_name' => 'Veli',
            'receiver_phone' => '555',
            'receiver_address' => 'Adres',
            'receiver_city' => 'Ankara',
        ]);

        $this->actingAs($user)
            ->get(route('shipments.index'))
            ->assertOk()
            ->assertSee('ARAS-ABC');
    }
}
