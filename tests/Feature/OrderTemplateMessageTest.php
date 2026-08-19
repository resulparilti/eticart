<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendOrderTemplateMessage;
use App\Models\Setting;
use App\Models\ShopifyOrder;
use App\Models\User;
use App\Support\OrderMessageTemplates;
use Database\Seeders\TemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OrderTemplateMessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_shared_mail_and_sms_templates(): void
    {
        (new TemplateSeeder())->run();

        foreach (OrderMessageTemplates::definitions() as $definition) {
            $this->assertDatabaseHas('mail_templates', [
                'slug' => $definition['mail_slug'],
                'name' => $definition['label'],
            ]);
            $this->assertDatabaseHas('sms_templates', [
                'slug' => $definition['sms_slug'],
                'name' => $definition['label'],
            ]);
        }
    }

    public function test_order_detail_queues_mail_template(): void
    {
        Queue::fake();
        $user = User::factory()->create(['email_verified_at' => now()]);
        (new TemplateSeeder())->run();
        $order = $this->order();

        $this->actingAs($user)
            ->post(route('orders.template-message', $order), [
                'channel' => 'mail',
                'template_key' => OrderMessageTemplates::ORDER_CONFIRMATION,
            ])
            ->assertRedirect(route('orders.show', $order))
            ->assertSessionHas('success');

        Queue::assertPushed(SendOrderTemplateMessage::class, function (SendOrderTemplateMessage $job) use ($order): bool {
            return $job->orderId === $order->id
                && $job->channel === 'mail'
                && $job->templateKey === OrderMessageTemplates::ORDER_CONFIRMATION;
        });
    }

    public function test_queued_mail_job_sends_replaced_template(): void
    {
        Mail::fake();
        (new TemplateSeeder())->run();
        $order = $this->order(['order_number' => '#TPL-1']);

        $activity = app(\App\Services\CustomerMessageService::class)
            ->queueOrderTemplate($order, 'mail', OrderMessageTemplates::ORDER_CONFIRMATION, null);

        $this->assertDatabaseHas('message_notifications', [
            'type' => 'mail',
            'recipient' => 'ayse@example.com',
            'status' => 'sent',
        ]);
        $this->assertSame('completed', $activity->fresh()->status);
        $this->assertStringContainsString('#TPL-1', (string) \App\Models\Notification::query()->latest('id')->value('subject'));
    }

    public function test_orders_index_has_context_menu_and_templates(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $this->order();

        $this->actingAs($user)
            ->get(route('orders.index'))
            ->assertOk()
            ->assertSee('orderContextMenu', false)
            ->assertSee('order-return-exchange', false)
            ->assertSee('SMS gönder');
    }

    public function test_message_send_page_is_manual_only(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)
            ->get(route('messages.send'))
            ->assertOk()
            ->assertSee('Mesaj Gönder')
            ->assertDontSee('Gönderim biçimi')
            ->assertSee('serbest metin');
    }

    public function test_list_json_endpoint_queues_job(): void
    {
        Queue::fake();
        $user = User::factory()->create(['email_verified_at' => now()]);
        (new TemplateSeeder())->run();
        $order = $this->order();

        $this->actingAs($user)
            ->postJson(route('orders.template-message', $order), [
                'channel' => 'mail',
                'template_key' => OrderMessageTemplates::ORDER_DELIVERED,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        Queue::assertPushed(SendOrderTemplateMessage::class);
    }

    public function test_sms_template_send_uses_provider_when_configured(): void
    {
        Http::fake(['*' => Http::response('00', 200)]);
        Setting::setValue('sms_provider', 'netgsm', 'sms');
        Setting::setValue('sms_api_key', 'test-key', 'sms');
        Setting::setValue('sms_api_secret', 'test-secret', 'sms');
        $this->app->forgetInstance(\App\Services\SmsService::class);
        $this->app->forgetInstance(\App\Services\CustomerMessageService::class);

        (new TemplateSeeder())->run();
        $order = $this->order(['customer_phone' => '05551234567']);

        app(\App\Services\CustomerMessageService::class)
            ->sendOrderTemplate($order, 'sms', OrderMessageTemplates::ORDER_SHIPPED);

        $this->assertDatabaseHas('message_notifications', [
            'type' => 'sms',
            'status' => 'sent',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function order(array $overrides = []): ShopifyOrder
    {
        return ShopifyOrder::query()->create(array_merge([
            'shopify_order_id' => '9001',
            'order_number' => '#9001',
            'customer_name' => 'Ayşe',
            'customer_email' => 'ayse@example.com',
            'customer_phone' => '05551112233',
            'total_price' => 250,
            'currency' => 'TRY',
            'payment_status' => 'paid',
            'fulfillment_status' => 'unfulfilled',
            'shopify_created_at' => now(),
            'synced_at' => now(),
        ], $overrides));
    }
}
