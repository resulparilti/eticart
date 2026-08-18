<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminNotification;
use App\Models\MailTemplate;
use App\Models\SmsTemplate;
use App\Models\UyumSoftProduct;
use App\Models\User;
use App\Services\ProductSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AdminNotificationAndTemplatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_sidebar_and_bell_use_new_labels(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        AdminNotification::query()->create([
            'type' => AdminNotification::TYPE_ORDER_CREATED,
            'title' => '#1001 yeni sipariş',
            'message' => 'Test',
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Bilgilendirmeler')
            ->assertSee('#1001 yeni sipariş');

        $this->actingAs($user)
            ->get(route('alerts.index'))
            ->assertOk()
            ->assertSee('Bildirimler')
            ->assertSee('#1001 yeni sipariş');

        $this->actingAs($user)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Bilgilendirmeler');
    }

    public function test_product_upsert_skips_unchanged_and_notifies_on_change(): void
    {
        $service = app(ProductSyncService::class);
        $data = [
            'uyumsoft_id' => 'P1',
            'sku' => 'SKU1',
            'barcode' => '123',
            'title' => 'Gömlek',
            'description' => 'Açıklama',
            'original_price' => 100,
            'stock' => 5,
            'variant_info' => null,
        ];

        $first = $service->upsertUyumSoftProduct($data);
        $this->assertTrue($first['created']);
        $this->assertTrue($first['changed']);

        $second = $service->upsertUyumSoftProduct($data);
        $this->assertFalse($second['created']);
        $this->assertFalse($second['changed']);

        $data['stock'] = 2;
        $third = $service->upsertUyumSoftProduct($data);
        $this->assertTrue($third['changed']);
        $this->assertSame(2, $third['product']->stock);

        $this->assertDatabaseHas('admin_notifications', [
            'type' => AdminNotification::TYPE_PRODUCT_CREATED,
        ]);
        $this->assertDatabaseHas('admin_notifications', [
            'type' => AdminNotification::TYPE_PRODUCT_UPDATED,
        ]);
        $this->assertSame(1, UyumSoftProduct::query()->count());
    }

    public function test_mail_template_test_send_returns_json(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email_verified_at' => now()]);
        $template = MailTemplate::query()->create([
            'name' => 'Kargo',
            'slug' => 'shipment-notification',
            'subject' => 'Kargo {{order_number}}',
            'body' => '<p>Sayın {{customer_name}}, takip {{tracking_number}}</p>',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('settings.templates.mail'))
            ->assertOk()
            ->assertSee('Test maili gönder');

        $this->actingAs($user)
            ->postJson(route('settings.templates.mail.test', $template), [
                'email' => 'test@example.com',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_sms_template_page_has_variables_and_test(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        SmsTemplate::query()->create([
            'name' => 'Kargo SMS',
            'slug' => 'shipment-sms',
            'body' => 'Sayın {{customer_name}}, {{order_number}} kargoya verildi. Takip: {{tracking_number}}',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('settings.templates.sms'))
            ->assertOk()
            ->assertSee('{{tracking_number}}', false)
            ->assertSee('Test SMS gönder');
    }
}
