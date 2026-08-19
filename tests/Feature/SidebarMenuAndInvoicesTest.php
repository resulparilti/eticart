<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminNotification;
use App\Models\CargoCompany;
use App\Models\Shipment;
use App\Models\ShopifyOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SidebarMenuAndInvoicesTest extends TestCase
{
    use RefreshDatabase;

    public function test_sidebar_shows_badges_and_new_order(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        Role::findOrCreate('admin', 'web');

        ShopifyOrder::query()->create([
            'shopify_order_id' => '3001',
            'order_number' => '#3001',
            'customer_name' => 'Ayşe',
            'customer_email' => 'ayse@example.com',
            'total_price' => 80,
            'currency' => 'TRY',
            'payment_status' => 'paid',
            'fulfillment_status' => 'preparing',
            'shopify_created_at' => now(),
            'synced_at' => now(),
        ]);

        ShopifyOrder::query()->create([
            'shopify_order_id' => '3002',
            'order_number' => '#3002',
            'customer_name' => 'Teslim',
            'customer_email' => 'teslim@example.com',
            'total_price' => 40,
            'currency' => 'TRY',
            'payment_status' => 'paid',
            'fulfillment_status' => 'delivered',
            'shopify_created_at' => now(),
            'synced_at' => now(),
        ]);

        $company = CargoCompany::query()->create([
            'name' => 'Yurtiçi',
            'provider_type' => 'yurtici',
            'is_active' => true,
        ]);

        $openOrder = ShopifyOrder::query()->where('order_number', '#3001')->firstOrFail();
        Shipment::query()->create([
            'shopify_order_id' => $openOrder->id,
            'cargo_company_id' => $company->id,
            'order_number' => '#3001',
            'tracking_number' => 'YK-OPEN',
            'status' => Shipment::STATUS_SHIPPED,
            'receiver_name' => 'Ayşe',
            'receiver_phone' => '555',
            'receiver_address' => 'Adres',
            'receiver_city' => 'İstanbul',
        ]);

        AdminNotification::query()->create([
            'type' => AdminNotification::TYPE_ORDER_CREATED,
            'title' => 'Okunmamış bildirim',
            'message' => 'Test',
        ]);

        $html = $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSeeInOrder([
                'Anasayfa',
                'Siparişler',
                'Ürünler',
                'Müşteriler',
                'Kargolar',
                'Faturalar',
                'Bildirimler',
                'Mesaj bilgilendirmeleri',
                'İşlem geçmişi',
                'Ayarlar',
                'Çıkış yap',
            ])
            ->assertDontSee('Kullanıcılar')
            ->content();

        $this->assertStringContainsString('eticart-nav-badge', $html);
    }

    public function test_order_badge_counts_only_pre_shipment_statuses(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $rows = [
            ['shopify_order_id' => '5001', 'order_number' => '#5001', 'fulfillment_status' => 'unfulfilled'],
            ['shopify_order_id' => '5002', 'order_number' => '#5002', 'fulfillment_status' => 'partial'],
            ['shopify_order_id' => '5003', 'order_number' => '#5003', 'fulfillment_status' => 'preparing'],
            ['shopify_order_id' => '5004', 'order_number' => '#5004', 'fulfillment_status' => null],
            ['shopify_order_id' => '5005', 'order_number' => '#5005', 'fulfillment_status' => 'fulfilled'],
            ['shopify_order_id' => '5006', 'order_number' => '#5006', 'fulfillment_status' => 'delivered'],
            ['shopify_order_id' => '5007', 'order_number' => '#5007', 'fulfillment_status' => 'cancelled'],
        ];

        foreach ($rows as $row) {
            ShopifyOrder::query()->create(array_merge([
                'customer_name' => 'Sayaç',
                'customer_email' => 'sayac@example.com',
                'total_price' => 10,
                'currency' => 'TRY',
                'payment_status' => 'paid',
                'shopify_created_at' => now(),
                'synced_at' => now(),
            ], $row));
        }

        $this->actingAs($user);

        $this->assertSame(4, app(\App\Services\SidebarMenuService::class)->counts()['open_orders']);
    }

    public function test_dashboard_cards_and_recent_orders_are_linked(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $order = ShopifyOrder::query()->create([
            'shopify_order_id' => '6101',
            'order_number' => '#6101',
            'customer_name' => 'Kart Müşteri',
            'customer_email' => 'kart@example.com',
            'total_price' => 99,
            'currency' => 'TRY',
            'payment_status' => 'paid',
            'fulfillment_status' => 'unfulfilled',
            'shopify_created_at' => now(),
            'synced_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Anasayfa')
            ->assertDontSee('>Dashboard<', false)
            ->assertSee(route('orders.index'), false)
            ->assertSee(route('products.index'), false)
            ->assertSee(route('shipments.index'), false)
            ->assertSee(route('reports.sales'), false)
            ->assertSee(route('orders.show', $order), false)
            ->assertSee('#6101');
    }

    public function test_invoices_index_lists_uyumsoft_invoices(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        ShopifyOrder::query()->create([
            'shopify_order_id' => '4001',
            'order_number' => '#4001',
            'customer_name' => 'Fatura Müşteri',
            'customer_email' => 'fatura@example.com',
            'total_price' => 250,
            'currency' => 'TRY',
            'payment_status' => 'paid',
            'fulfillment_status' => 'fulfilled',
            'uyumsoft_invoice_no' => 'ETF2026001',
            'uyumsoft_einvoice_uuid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'shopify_created_at' => now(),
            'synced_at' => now(),
        ]);

        ShopifyOrder::query()->create([
            'shopify_order_id' => '4002',
            'order_number' => '#4002',
            'customer_name' => 'Faturasız',
            'customer_email' => 'yok@example.com',
            'total_price' => 10,
            'currency' => 'TRY',
            'payment_status' => 'paid',
            'fulfillment_status' => 'unfulfilled',
            'shopify_created_at' => now(),
            'synced_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('invoices.index'))
            ->assertOk()
            ->assertSee('Faturalar')
            ->assertSee('#4001')
            ->assertSee('ETF2026001')
            ->assertSee('Fatura Müşteri')
            ->assertDontSee('#4002');
    }

    public function test_settings_hub_shows_queue_for_admin(): void
    {
        $role = Role::findOrCreate('admin', 'web');
        $admin = User::factory()->create(['email_verified_at' => now()]);
        $admin->assignRole($role);

        $this->actingAs($admin)
            ->get(route('settings.index'))
            ->assertOk()
            ->assertSee('Kuyruk (Queue)')
            ->assertSee('Raporlar');
    }
}
