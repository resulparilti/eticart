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
            ->assertSee('Mesaj bilgilendirmeleri')
            ->assertSee('Anasayfa')
            ->assertSee('Faturalar')
            ->assertSee('Çıkış yap')
            ->assertSee('#1001 yeni sipariş');

        $this->actingAs($user)
            ->get(route('alerts.index'))
            ->assertOk()
            ->assertSee('Bildirimler')
            ->assertSee('#1001 yeni sipariş');

        $this->actingAs($user)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Mesaj bilgilendirmeleri');
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

    public function test_product_upsert_keeps_local_variant_images(): void
    {
        $service = app(ProductSyncService::class);
        $incoming = [
            'uyumsoft_id' => 'P-IMG',
            'sku' => 'SKU-IMG',
            'title' => 'Bere',
            'original_price' => 100,
            'stock' => 2,
            'variant_info' => [
                'variants' => [
                    [
                        'title' => 'Siyah',
                        'sku' => 'SKU-IMG-S',
                        'barcode' => '111',
                        'price' => 100,
                        'stock' => 1,
                    ],
                ],
            ],
        ];

        $created = $service->upsertUyumSoftProduct($incoming);
        $created['product']->update([
            'variant_info' => [
                'variants' => [[
                    'title' => 'Siyah',
                    'sku' => 'SKU-IMG-S',
                    'barcode' => '111',
                    'price' => 100,
                    'stock' => 1,
                    'image' => 'https://cdn.example.com/siyah.jpg',
                ]],
            ],
        ]);

        $incoming['stock'] = 3;
        $incoming['variant_info']['variants'][0]['price'] = 120;
        $result = $service->upsertUyumSoftProduct($incoming);

        $this->assertSame(3, $result['product']->stock);
        $this->assertSame(120, $result['product']->variant_info['variants'][0]['price']);
        $this->assertSame('https://cdn.example.com/siyah.jpg', $result['product']->variant_info['variants'][0]['image']);
    }

    public function test_mail_template_test_send_returns_json(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email_verified_at' => now()]);
        $template = MailTemplate::query()->updateOrCreate(
            ['slug' => 'shipment-notification'],
            [
                'name' => 'Kargo',
                'subject' => 'Kargo {{order_number}}',
                'body' => '<p>Sayın {{customer_name}}, takip {{tracking_number}}</p>',
                'is_active' => true,
            ]
        );

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
        SmsTemplate::query()->updateOrCreate(
            ['slug' => 'shipment-sms'],
            [
                'name' => 'Kargo SMS',
                'body' => 'Sayın {{customer_name}}, {{order_number}} kargoya verildi. Takip: {{tracking_number}}',
                'is_active' => true,
            ]
        );

        $this->actingAs($user)
            ->get(route('settings.templates.sms'))
            ->assertOk()
            ->assertSee('{{tracking_number}}', false)
            ->assertSee('Test SMS gönder');
    }

    public function test_settings_pages_seed_predefined_mail_and_sms_templates(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)
            ->get(route('settings.templates.mail'))
            ->assertOk()
            ->assertSee('Sipariş onay')
            ->assertSee('Sipariş kargoya verildi')
            ->assertSee('Sipariş faturası yüklendi')
            ->assertSee('Sipariş teslim edildi')
            ->assertSee('İade ve değişim')
            ->assertSee('Ön tanımlı');

        $this->actingAs($user)
            ->get(route('settings.templates.sms'))
            ->assertOk()
            ->assertSee('Sipariş onay')
            ->assertSee('order-confirmation-sms', false)
            ->assertSee('order-invoice-sms', false);

        $this->assertGreaterThanOrEqual(5, \App\Models\MailTemplate::query()->count());
        $this->assertGreaterThanOrEqual(5, \App\Models\SmsTemplate::query()->count());
    }

    public function test_dashboard_shows_disk_usage(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Disk kullanımı')
            ->assertSee('Faturalar')
            ->assertSee('Görseller')
            ->assertSee('Yazılım');
    }

    public function test_alert_read_redirects_without_strict_types_error(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $alert = AdminNotification::query()->create([
            'type' => AdminNotification::TYPE_ORDER_CREATED,
            'title' => 'Okunacak bildirim',
            'message' => 'Test',
            'url' => route('orders.index'),
        ]);

        $this->actingAs($user)
            ->get(route('alerts.read', $alert))
            ->assertRedirect(route('orders.index'));

        $this->assertNotNull($alert->fresh()->read_at);
    }
}
