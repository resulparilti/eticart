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

    public function test_production_staff_sees_floor_not_admin_pages(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $staff = $this->productionUser();

        $this->actingAs($staff)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Hazırlanmayı bekleyen')
            ->assertSee('Hazırlama');
        $this->actingAs($staff)->get(route('production.orders.index'))->assertOk();
        $this->actingAs($staff)->get(route('production.products.index'))->assertOk();
        $this->actingAs($staff)->get(route('orders.index'))->assertRedirect(route('production.orders.index'));
        $this->actingAs($staff)->get(route('products.index'))->assertRedirect(route('production.products.index'));
        $this->actingAs($staff)->get(route('settings.index'))->assertForbidden();
        $this->actingAs($staff)->get(route('users.index'))->assertForbidden();
        $this->actingAs($staff)->get(route('orders.archives.index'))->assertForbidden();
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
            ->get(route('production.orders.index', ['packed' => 1]))
            ->assertOk()
            ->assertSee('Hazırlandı')
            ->assertSee('order-packed-mark is-done', false)
            ->assertSee($order->order_number);

        $admin = User::factory()->create(['email_verified_at' => now()]);
        $this->actingAs($admin)
            ->get(route('orders.index'))
            ->assertOk()
            ->assertSee('order-packed-mark is-done', false)
            ->assertSee($order->order_number);

        $this->actingAs($staff)
            ->get(route('production.orders.show', $order))
            ->assertOk()
            ->assertDontSee('ayse@example.com')
            ->assertDontSee('Fatura')
            ->assertSee('Sipariş hazırlama');
    }

    public function test_production_orders_index_lists_unpacked_orders_with_varied_statuses(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $staff = $this->productionUser();

        $this->order(['order_number' => '#WAIT-1', 'fulfillment_status' => 'unfulfilled']);
        $this->order(['order_number' => '#PREP-1', 'fulfillment_status' => 'preparing']);
        $this->order(['order_number' => '#SHIP-1', 'fulfillment_status' => 'fulfilled']);
        $this->order(['order_number' => '#CANC-1', 'fulfillment_status' => 'cancelled']);
        $this->order(['order_number' => '#DEL-1', 'fulfillment_status' => 'delivered']);
        $this->order([
            'order_number' => '#PACK-1',
            'fulfillment_status' => 'unfulfilled',
            'packed_at' => now(),
            'packed_by_name' => 'Ali',
        ]);

        $this->actingAs($staff)
            ->get(route('production.orders.index'))
            ->assertOk()
            ->assertSee('#WAIT-1')
            ->assertSee('#PREP-1')
            ->assertSee('#SHIP-1')
            ->assertSee('#CANC-1')
            ->assertSee('#DEL-1')
            ->assertSee('#PACK-1');

        $this->actingAs($staff)
            ->get(route('production.orders.index', ['packed' => '0']))
            ->assertOk()
            ->assertSee('#WAIT-1')
            ->assertDontSee('#CANC-1')
            ->assertDontSee('#DEL-1')
            ->assertDontSee('#PACK-1');

        $this->actingAs($staff)
            ->get(route('production.orders.index', ['packed' => 1]))
            ->assertOk()
            ->assertSee('#PACK-1')
            ->assertSee('order-packed-mark is-done', false)
            ->assertDontSee('#WAIT-1');
    }

    public function test_admin_can_reset_packing_and_deletes_photo_with_log(): void
    {
        Storage::fake('public');
        $this->seed(RolePermissionSeeder::class);
        $staff = $this->productionUser();
        $admin = $this->adminUser('Yönetici Ali');
        $order = $this->order(['order_number' => '#9901']);

        $this->actingAs($staff)
            ->post(route('orders.packing.complete', $order), [
                'gift_box' => '0',
                'checklist' => $this->checkedAlways(),
                'photo' => UploadedFile::fake()->image('pack.jpg', 800, 600),
            ])
            ->assertRedirect();

        $order->refresh();
        $this->assertTrue($order->isPacked());
        $photo = (string) $order->packing_photo_path;
        Storage::disk('public')->assertExists($photo);

        $this->actingAs($admin)
            ->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee('Hazırlamayı sıfırla');

        $this->actingAs($staff)
            ->from(route('production.orders.show', $order))
            ->post(route('orders.packing.reset', $order))
            ->assertForbidden();

        $this->actingAs($admin)
            ->from(route('orders.show', $order))
            ->post(route('orders.packing.reset', $order))
            ->assertRedirect(route('orders.show', $order))
            ->assertSessionHas('success');

        $order->refresh();
        $this->assertFalse($order->isPacked());
        $this->assertNull($order->packing_photo_path);
        $this->assertNull($order->packed_by_name);
        $this->assertNull($order->packing_checklist);
        Storage::disk('public')->assertMissing($photo);

        $log = ActivityLog::query()
            ->where('user_id', $admin->id)
            ->where('action', 'prepare')
            ->where('subject_id', $order->id)
            ->latest('id')
            ->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('hazırlanma prosedürünü iptal etti', (string) $log->description);
        $this->assertStringContainsString('#9901', (string) $log->description);
    }

    public function test_second_staff_cannot_continue_packing_started_by_another(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $first = $this->productionUser('Ali');
        $second = $this->productionUser('Veli');
        $order = $this->order();

        $this->actingAs($first)
            ->get(route('production.orders.show', $order))
            ->assertOk();

        $order->refresh();
        $this->assertSame($first->id, $order->packing_started_by_user_id);

        $this->actingAs($second)
            ->postJson(route('orders.packing.claim', $order))
            ->assertStatus(409)
            ->assertJsonPath('can_start', false)
            ->assertJsonPath('message', \App\Services\OrderPackingService::LOCKED_MESSAGE);

        $this->actingAs($second)
            ->get(route('production.orders.show', $order))
            ->assertOk()
            ->assertSee(\App\Services\OrderPackingService::LOCKED_MESSAGE, false);

        $this->actingAs($first)
            ->patchJson(route('orders.packing.checklist', $order), [
                'gift_box' => false,
                'checklist' => array_merge($this->checkedAlways(), ['tissue_paper' => 1]),
                'item' => 'tissue_paper',
                'checked' => true,
            ])
            ->assertOk();

        $order->refresh();
        $this->assertSame($first->id, $order->packing_started_by_user_id);

        $this->actingAs($second)
            ->getJson(route('orders.packing.status', $order))
            ->assertOk()
            ->assertJsonPath('can_start', false)
            ->assertJsonPath('message', \App\Services\OrderPackingService::LOCKED_MESSAGE);

        $this->actingAs($second)
            ->patchJson(route('orders.packing.checklist', $order), [
                'gift_box' => false,
                'checklist' => $this->checkedAlways(),
                'item' => 'brand_labels',
                'checked' => true,
            ])
            ->assertStatus(409)
            ->assertJsonPath('ok', false);

        $admin = $this->adminUser();
        $this->actingAs($admin)
            ->patchJson(route('orders.packing.checklist', $order), [
                'gift_box' => false,
                'checklist' => $this->checkedAlways(),
                'item' => 'brand_labels',
                'checked' => true,
            ])
            ->assertStatus(409);

        $this->actingAs($first)
            ->getJson(route('orders.packing.status', $order))
            ->assertOk()
            ->assertJsonPath('can_start', true);

        $this->actingAs($second)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Bugün hazırladıklarım')
            ->assertSee('Devam ettiklerim');
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
            ->from(route('production.orders.show', $order))
            ->post(route('orders.packing.complete', $order), [
                'gift_box' => '1',
                'gift_box_size' => 'Orta',
                'checklist' => $this->checkedAlways(),
            ])
            ->assertRedirect(route('production.orders.show', $order))
            ->assertSessionHas('error');

        $this->assertFalse($order->fresh()->isPacked());
    }

    public function test_gift_box_requires_size_before_complete(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $staff = $this->productionUser();
        $order = $this->order();

        $this->actingAs($staff)
            ->from(route('production.orders.show', $order))
            ->post(route('orders.packing.complete', $order), [
                'gift_box' => '1',
                'gift_box_size' => '',
                'checklist' => array_merge($this->checkedAlways(), [
                    'gift_box' => 1,
                    'gift_card' => 1,
                ]),
            ])
            ->assertRedirect(route('production.orders.show', $order))
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

    private function adminUser(string $name = 'Yönetici'): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'email_verified_at' => now(),
        ]);
        $user->assignRole('admin');

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
