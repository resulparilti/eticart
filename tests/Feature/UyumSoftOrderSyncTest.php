<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\ShopifyOrder;
use App\Models\ShopifyOrderItem;
use App\Models\SyncActivity;
use App\Models\SyncJob;
use App\Models\User;
use App\Services\UyumSoftEInvoiceService;
use App\Services\UyumSoftOrderSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UyumSoftOrderSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.uyumsoft.username' => 'WEBSERVIS',
            'services.uyumsoft.password' => 'secret',
            'services.uyumsoft.base_url' => 'https://tenant.eko.uyumcloud.com',
            'services.uyumsoft.branch_code' => '001',
            'services.uyumsoft.warehouse_id' => 'A001',
        ]);

        Setting::setValue('uyumsoft_api_user', 'WEBSERVIS', 'uyumsoft');
        Setting::setValue('uyumsoft_api_password', 'secret', 'uyumsoft');
        Setting::setValue('uyumsoft_base_url', 'https://tenant.eko.uyumcloud.com', 'uyumsoft');
        Setting::setValue('uyumsoft_branch_code', '001', 'uyumsoft');
        Setting::setValue('uyumsoft_warehouse_id', 'A001', 'uyumsoft');
        Setting::setValue('uyumsoft_ecommerce_entity_code', 'ETICARET', 'uyumsoft');
    }

    public function test_new_shopify_order_is_pushed_and_invoice_pdf_is_attached(): void
    {
        Storage::fake('public');
        $this->fakeCloudApi();

        $order = $this->makeOrder();

        $result = app(UyumSoftOrderSyncService::class)->sync(10);

        $this->assertSame(1, $result['pushed']);
        $this->assertSame(1, $result['invoices']);
        $this->assertSame(0, $result['errors']);

        $order->refresh();
        $this->assertSame('98765', $order->uyumsoft_order_id);
        $this->assertSame('555', $order->uyumsoft_invoice_id);
        $this->assertSame('INV-1', $order->uyumsoft_invoice_no);
        $this->assertNotNull($order->invoice_path);
        $this->assertTrue(Storage::disk('public')->exists($order->invoice_path));
        $this->assertNotNull($order->uyumsoft_pushed_at);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'PSM/InsertOrderM'));
    }

    public function test_official_invoice_is_linked_without_storing_a_file(): void
    {
        Storage::fake('public');
        $this->fakeCloudApi(withPdf: false);
        $this->mock(UyumSoftEInvoiceService::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->andReturn(true)->zeroOrMoreTimes();
            $mock->shouldReceive('hasDedicatedCredentials')->andReturn(true)->zeroOrMoreTimes();
            $mock->shouldReceive('findOutboxInvoice')->never();
            $mock->shouldReceive('downloadOfficialDocument')->never();
            $mock->shouldReceive('downloadByDocumentNumber')->never();
        });

        $order = $this->makeOrder();
        $result = app(UyumSoftOrderSyncService::class)->sync(10);

        $this->assertSame(1, $result['invoices']);
        $order->refresh();
        $this->assertSame('INV-1', $order->uyumsoft_invoice_no);
        $this->assertSame('6720a6b7-613f-4104-b283-642a46508454', $order->uyumsoft_einvoice_uuid);
        $this->assertNull($order->invoice_path);
        $this->assertTrue($order->hasInvoice());
        $this->assertFalse($order->hasLocalInvoiceFile());
        Storage::disk('public')->assertDirectoryEmpty('order-invoices');
    }

    public function test_existing_uyumsoft_order_is_not_created_again(): void
    {
        Storage::fake('public');
        $this->fakeCloudApi();

        $order = $this->makeOrder([
            'uyumsoft_order_id' => '98765',
            'uyumsoft_pushed_at' => now()->subDay(),
        ]);

        $result = app(UyumSoftOrderSyncService::class)->syncOrder($order);

        $this->assertFalse($result['pushed']);
        $this->assertTrue($result['invoice']);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'SaveOrder'));
    }

    public function test_updated_order_content_is_written_to_existing_uyumsoft_order(): void
    {
        Storage::fake('public');
        $this->fakeCloudApi();

        $order = $this->makeOrder([
            'uyumsoft_order_id' => '98765',
            'uyumsoft_pushed_at' => now()->subDay(),
            'total_price' => 199.90,
        ]);
        $order->items()->update(['quantity' => 2, 'price' => 99.95]);
        $order->update([
            'total_price' => 199.90,
            'shopify_content_hash' => 'old-hash',
            'uyumsoft_content_hash' => 'old-hash',
            'uyumsoft_needs_update' => true,
        ]);

        $result = app(UyumSoftOrderSyncService::class)->sync(10);

        $this->assertSame(1, $result['pushed']);
        $order->refresh();
        $this->assertFalse((bool) $order->uyumsoft_needs_update);
        $this->assertNotSame('old-hash', $order->uyumsoft_content_hash);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'UpdateOrder') || str_contains($request->url(), 'SaveOrder'));
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'InsertOrder'));
    }

    public function test_invoiced_order_content_change_sets_lock_warning(): void
    {
        Storage::fake('public');
        $this->fakeCloudApi();

        $order = $this->makeOrder([
            'uyumsoft_order_id' => '98765',
            'uyumsoft_invoice_id' => '555',
            'invoice_path' => 'order-invoices/1/fatura.pdf',
            'uyumsoft_needs_update' => true,
            'shopify_content_hash' => 'new-hash',
            'uyumsoft_content_hash' => 'old-hash',
        ]);

        $result = app(UyumSoftOrderSyncService::class)->syncOrder($order->fresh(['items']) ?? $order);

        $this->assertFalse($result['pushed']);
        $order->refresh();
        $this->assertTrue((bool) $order->uyumsoft_invoice_locked);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'UpdateOrder') || str_contains($request->url(), 'SaveOrder') || str_contains($request->url(), 'InsertOrder'));
    }

    public function test_cancelled_order_is_not_pushed(): void
    {
        $this->fakeCloudApi();
        $order = $this->makeOrder(['fulfillment_status' => 'cancelled']);

        $result = app(UyumSoftOrderSyncService::class)->sync(10);

        $this->assertSame(0, $result['pushed']);
        $order->refresh();
        $this->assertNull($order->uyumsoft_order_id);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'SaveOrder'));
    }

    public function test_order_page_can_trigger_uyumsoft_sync(): void
    {
        Storage::fake('public');
        $this->fakeCloudApi();
        $user = User::factory()->create(['email_verified_at' => now()]);
        $order = $this->makeOrder();

        $this->actingAs($user)
            ->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee('UyumSoft');

        $this->actingAs($user)
            ->get(route('orders.uyumsoft-sync', $order))
            ->assertRedirect(route('orders.show', $order));

        $this->actingAs($user)
            ->post(route('orders.uyumsoft-sync', $order))
            ->assertRedirect(route('orders.show', $order))
            ->assertSessionHas('success');

        $order->refresh();
        $this->assertNotNull($order->uyumsoft_order_id);
        $this->assertNotNull($order->invoice_path);

        $activity = SyncActivity::query()
            ->where('type', 'uyumsoft_order_sync')
            ->where('title', $order->order_number.' UyumSoft gönder / fatura çek')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertContains($activity->status, [SyncActivity::STATUS_COMPLETED, SyncActivity::STATUS_PARTIAL]);
        $this->assertTrue($activity->logs()->exists());
    }

    public function test_cron_skips_uyumsoft_job_until_interval_elapsed(): void
    {
        SyncJob::query()->create([
            'job_type' => 'order_sync',
            'interval_minutes' => 15,
            'is_active' => false,
            'status' => 'idle',
        ]);
        SyncJob::query()->create([
            'job_type' => 'stock_sync',
            'interval_minutes' => 15,
            'is_active' => false,
            'status' => 'idle',
        ]);
        SyncJob::query()->create([
            'job_type' => 'product_sync',
            'interval_minutes' => 30,
            'is_active' => false,
            'status' => 'idle',
        ]);
        SyncJob::query()->create([
            'job_type' => 'cargo_tracking',
            'interval_minutes' => 15,
            'is_active' => false,
            'status' => 'idle',
        ]);
        SyncJob::query()->create([
            'job_type' => 'uyumsoft_order_sync',
            'interval_minutes' => 15,
            'is_active' => true,
            'status' => 'idle',
            'last_run' => now()->subMinutes(3),
            'next_run' => now()->addMinutes(12),
        ]);

        $this->artisan('eticart:cron-run')
            ->expectsOutputToContain('skip uyumsoft_order_sync')
            ->assertSuccessful();
    }

    public function test_cron_runs_uyumsoft_job_when_due(): void
    {
        Storage::fake('public');
        $this->fakeCloudApi();
        $this->makeOrder();

        foreach (['order_sync', 'stock_sync', 'product_sync', 'cargo_tracking'] as $type) {
            SyncJob::query()->create([
                'job_type' => $type,
                'interval_minutes' => 15,
                'is_active' => false,
                'status' => 'idle',
            ]);
        }

        SyncJob::query()->create([
            'job_type' => 'uyumsoft_order_sync',
            'interval_minutes' => 15,
            'is_active' => true,
            'status' => 'idle',
            'last_run' => now()->subMinutes(20),
            'next_run' => now()->subMinutes(5),
        ]);

        $this->artisan('eticart:cron-run')
            ->expectsOutputToContain('ok uyumsoft_order_sync')
            ->assertSuccessful();

        $this->assertDatabaseHas('shopify_orders', [
            'order_number' => '#1002',
            'uyumsoft_order_id' => '98765',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeOrder(array $overrides = []): ShopifyOrder
    {
        $order = ShopifyOrder::query()->create(array_merge([
            'shopify_order_id' => '1002',
            'order_number' => '#1002',
            'customer_name' => 'Ayşe',
            'customer_email' => 'ayse@example.com',
            'shipping_address' => 'Test Mah. No 1',
            'shipping_city' => 'Kadıköy',
            'shipping_province' => 'İstanbul',
            'shipping_zip' => '34710',
            'total_price' => 199.90,
            'currency' => 'TRY',
            'payment_status' => 'paid',
            'fulfillment_status' => 'unfulfilled',
            'shopify_created_at' => now()->subHour(),
            'synced_at' => now(),
        ], $overrides));

        ShopifyOrderItem::query()->create([
            'shopify_order_id' => $order->id,
            'shopify_line_item_id' => '11',
            'product_title' => 'Bluz',
            'variant_title' => 'Siyah / M',
            'sku' => 'BLZ-001',
            'quantity' => 1,
            'price' => 199.90,
        ]);

        $order = $order->fresh(['items']) ?? $order;
        if (filled($order->uyumsoft_order_id) && blank($order->uyumsoft_content_hash)) {
            $hash = $order->contentHash();
            $order->update([
                'shopify_content_hash' => $hash,
                'uyumsoft_content_hash' => $hash,
                'uyumsoft_needs_update' => false,
            ]);
        }

        return $order->fresh(['items']) ?? $order;
    }

    private function fakeCloudApi(bool $withPdf = true): void
    {
        Http::fake(function ($request) use ($withPdf) {
            $url = $request->url();

            if (str_contains($url, 'GNL/UyumLogin')) {
                return Http::response([
                    'statusCode' => 200,
                    'result' => [
                        'access_token' => 'token-abc',
                        'uyumSecretKey' => 'secret-key',
                    ],
                ], 200);
            }

            if (str_contains($url, 'GetInvoicePdf') || str_contains($url, 'GetInvoiceMPdf') || str_contains($url, 'GetDocumentPdf')) {
                if (! $withPdf) {
                    return Http::response('<html>File or directory not found</html>', 404);
                }
                return Http::response([
                    'statusCode' => 200,
                    'result' => [
                        'pdf' => base64_encode('%PDF-1.4 test invoice'),
                    ],
                ], 200);
            }

            if (str_contains($url, 'GetInvoice')) {
                $invoice = [
                    'id' => 555,
                    'docNo' => 'SH1002',
                    'invoiceNo' => 'INV-1',
                    'note1' => 'Shopify #1002',
                    'gnlNote6' => 'Sipariş Numarası: 1002',
                ];
                if (! $withPdf) {
                    $invoice['guID'] = '6720a6b7-613f-4104-b283-642a46508454';
                    $invoice['eDocNo'] = 'ORE2026000000001';
                }

                return Http::response([
                    'statusCode' => 200,
                    'result' => [$invoice],
                ], 200);
            }

            if (str_contains($url, 'GetOrder')) {
                return Http::response([
                    'statusCode' => 200,
                    'result' => [],
                ], 200);
            }

            if (str_contains($url, 'InsertOrder') || str_contains($url, 'SaveOrder')) {
                return Http::response([
                    'statusCode' => 200,
                    'result' => [
                        'id' => 98765,
                        'docNo' => 'SH1002',
                    ],
                ], 200);
            }

            return Http::response(['statusCode' => 404, 'message' => 'not found'], 404);
        });
    }
}
