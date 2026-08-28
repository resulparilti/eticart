<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\ShopifyOrder;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OrderPackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_staff_sees_orders_and_products_but_not_settings(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $staff = $this->productionUser();

        $this->actingAs($staff)->get(route('orders.index'))->assertOk();
        $this->actingAs($staff)->get(route('products.index'))->assertOk();
        $this->actingAs($staff)->get(route('settings.index'))->assertForbidden();
        $this->actingAs($staff)->get(route('users.index'))->assertForbidden();
        $this->actingAs($staff)->get(route('orders.archives.index'))->assertForbidden();
        $this->actingAs($staff)->get(route('dashboard'))->assertRedirect(route('orders.index', ['open' => 1]));
    }

    public function test_packing_complete_without_photo_marks_order_and_writes_log(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $staff = $this->productionUser('Resul Parıltı');
        $order = $this->order();

        $this->actingAs($staff)
            ->patchJson(route('orders.packing.checklist', $order), [
                'gift_box' => false,
                'checklist' => $this->checkedAlways(),
                'item' => 'tissue_paper',
                'checked' => true,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->actingAs($staff)
            ->post(route('orders.packing.complete', $order), [
                'gift_box' => '0',
                'checklist' => $this->checkedAlways(),
            ])
            ->assertRedirect();

        $order->refresh();
        $this->assertTrue($order->isPacked());
        $this->assertSame('Resul Parıltı', $order->packed_by_name);
        $this->assertNull($order->packing_photo_path);

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $staff->id,
            'action' => 'prepare',
            'subject_id' => $order->id,
        ]);

        $completeLog = ActivityLog::query()
            ->where('action', 'prepare')
            ->where('subject_id', $order->id)
            ->latest('id')
            ->first();
        $this->assertNotNull($completeLog);
        $this->assertStringContainsString('hazırladı', (string) $completeLog->description);
        $this->assertStringContainsString('Pelur kağıt kullanıldı', (string) $completeLog->description);

        $this->actingAs($staff)
            ->get(route('orders.index'))
            ->assertOk()
            ->assertSee('is-packed', false)
            ->assertSee('Resul Parıltı');
    }

    public function test_packing_photo_is_compressed_with_order_user_and_date_in_name(): void
    {
        Storage::fake('public');
        $this->seed(RolePermissionSeeder::class);
        $staff = $this->productionUser();
        $order = $this->order(['order_number' => '#8844']);

        $this->actingAs($staff)
            ->post(route('orders.packing.complete', $order), [
                'gift_box' => '0',
                'checklist' => $this->checkedAlways(),
                'photo' => UploadedFile::fake()->image('camera.jpg', 2400, 1800),
            ])
            ->assertRedirect();

        $order->refresh();
        $this->assertNotNull($order->packing_photo_path);
        $this->assertStringContainsString('8844', (string) $order->packing_photo_path);
        $this->assertStringContainsString('_u'.$staff->id.'_', (string) $order->packing_photo_path);
        $this->assertStringEndsWith('.jpg', (string) $order->packing_photo_path);
        Storage::disk('public')->assertExists($order->packing_photo_path);
    }

    public function test_gift_box_items_are_required_when_gift_is_selected(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $staff = $this->productionUser();
        $order = $this->order();

        $this->actingAs($staff)
            ->from(route('orders.show', $order))
            ->post(route('orders.packing.complete', $order), [
                'gift_box' => '1',
                'gift_box_size' => 'Orta',
                'checklist' => $this->checkedAlways(),
            ])
            ->assertRedirect(route('orders.show', $order))
            ->assertSessionHas('error');

        $this->assertFalse($order->fresh()->isPacked());
    }

    public function test_gift_box_requires_size_before_complete(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $staff = $this->productionUser();
        $order = $this->order();

        $this->actingAs($staff)
            ->from(route('orders.show', $order))
            ->post(route('orders.packing.complete', $order), [
                'gift_box' => '1',
                'gift_box_size' => '',
                'checklist' => array_merge($this->checkedAlways(), [
                    'gift_box' => 1,
                    'gift_card' => 1,
                ]),
            ])
            ->assertRedirect(route('orders.show', $order))
            ->assertSessionHas('error');

        $this->assertFalse($order->fresh()->isPacked());
    }

    /**
     * @return array<string, int>
     */
    private function checkedAlways(): array
    {
        return [
            'wash_instructions' => 1,
            'brand_labels' => 1,
            'tissue_paper' => 1,
            'tissue_sticker' => 1,
            'kraft_box' => 1,
            'branded_mailer' => 1,
            'gift_box' => 0,
            'gift_card' => 0,
        ];
    }

    private function productionUser(string $name = 'Üretim Personeli'): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'email_verified_at' => now(),
        ]);
        $user->assignRole('production');

        return $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function order(array $overrides = []): ShopifyOrder
    {
        return ShopifyOrder::query()->create(array_merge([
            'shopify_order_id' => (string) fake()->unique()->numerify('88###'),
            'order_number' => '#'.fake()->unique()->numerify('88##'),
            'customer_name' => 'Ayşe Yılmaz',
            'customer_email' => 'ayse@example.com',
            'total_price' => 120,
            'currency' => 'TRY',
            'payment_status' => 'paid',
            'fulfillment_status' => 'unfulfilled',
            'shopify_created_at' => now(),
            'synced_at' => now(),
        ], $overrides));
    }
}
