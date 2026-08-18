<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ShopifyOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_orders_index_requires_auth(): void
    {
        $this->get(route('orders.index'))->assertRedirect('/login');
    }

    public function test_orders_index_renders(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        ShopifyOrder::query()->create([
            'shopify_order_id' => '1001',
            'order_number' => '#1001',
            'customer_name' => 'Test Müşteri',
            'customer_email' => 'a@b.com',
            'total_price' => 150,
            'currency' => 'TRY',
            'payment_status' => 'paid',
            'fulfillment_status' => 'unfulfilled',
            'shopify_created_at' => now(),
            'synced_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('orders.index'))
            ->assertOk()
            ->assertSee('#1001');
    }

    public function test_order_show_page(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $order = ShopifyOrder::query()->create([
            'shopify_order_id' => '1002',
            'order_number' => '#1002',
            'customer_name' => 'Ali',
            'customer_email' => 'ali@example.com',
            'total_price' => 90,
            'currency' => 'TRY',
            'payment_status' => 'paid',
            'fulfillment_status' => 'unfulfilled',
            'shopify_created_at' => now(),
            'synced_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee('#1002')
            ->assertSee('Ali')
            ->assertSee('Senkronize et');
    }
}
