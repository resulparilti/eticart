<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\ShopifyOrder;
use App\Models\SyncActivity;
use App\Models\User;
use App\Services\OrderSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OrderBidirectionalSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_fulfilled_status_is_pushed_to_shopify(): void
    {
        $this->configureShopify();
        $user = User::factory()->create(['email_verified_at' => now()]);
        $order = $this->order(['fulfillment_status' => 'unfulfilled']);

        Http::fake(fn ($request) => $this->shopifyHttpResponse($request));

        $this->actingAs($user)
            ->patch(route('orders.update-status', $order), [
                'fulfillment_status' => 'fulfilled',
                'payment_status' => 'paid',
            ])
            ->assertRedirect(route('orders.show', $order));

        $order->refresh();
        $this->assertSame('fulfilled', $order->fulfillment_status);
        $this->assertFalse((bool) $order->shopify_needs_push);
        $this->assertNotNull($order->shopify_pushed_at);

        Http::assertSent(function ($request): bool {
            if ($request->method() !== 'POST' || ! str_contains($request->url(), 'fulfillments.json')) {
                return false;
            }
            $lines = data_get($request->data(), 'fulfillment.line_items_by_fulfillment_order', []);

            return $lines !== [] && (int) data_get($lines, '0.fulfillment_order_id') === 88;
        });

        Http::assertSent(function ($request): bool {
            if ($request->method() !== 'PUT' || ! str_contains($request->url(), 'orders/')) {
                return false;
            }
            $tags = (string) data_get($request->data(), 'order.tags');

            return str_contains($tags, 'Kargoya Verildi');
        });

        $this->assertDatabaseHas('sync_activities', [
            'type' => 'order_push',
            'status' => SyncActivity::STATUS_COMPLETED,
        ]);
    }

    public function test_invoice_upload_writes_eticart_invoice_to_shopify(): void
    {
        Storage::fake('public');
        $this->configureShopify();
        $user = User::factory()->create(['email_verified_at' => now()]);
        $order = $this->order();

        Http::fake(fn ($request) => $this->shopifyHttpResponse($request));

        $this->actingAs($user)
            ->post(route('orders.invoice.upload', $order), [
                'invoice' => UploadedFile::fake()->create('fatura.pdf', 80, 'application/pdf'),
            ])
            ->assertRedirect(route('orders.show', $order));

        $order->refresh();
        $invoiceUrl = $order->invoiceUrl();
        $this->assertNotNull($invoiceUrl);

        Http::assertSent(function ($request) use ($invoiceUrl): bool {
            if ($request->method() !== 'PUT' || ! str_contains($request->url(), 'orders/')) {
                return false;
            }
            $data = $request->data();
            $note = (string) data_get($data, 'order.note');
            if (! str_contains($note, 'Fatura: '.$invoiceUrl)) {
                return false;
            }
            $hasVisibleLink = false;
            foreach (data_get($data, 'order.note_attributes', []) as $attribute) {
                if (($attribute['name'] ?? '') === 'Fatura'
                    && ($attribute['value'] ?? '') === $invoiceUrl
                ) {
                    $hasVisibleLink = true;
                    break;
                }
            }
            $metafield = data_get($data, 'order.metafields.0.value');

            return $hasVisibleLink && $metafield === $invoiceUrl;
        });
    }

    public function test_scheduled_sync_adds_new_keeps_existing_and_pushes_local_updates(): void
    {
        $this->configureShopify();
        $existing = $this->order([
            'shopify_order_id' => '555',
            'order_number' => '#555',
            'customer_name' => 'Lokal İsim',
            'fulfillment_status' => 'fulfilled',
            'notes' => 'Lokal not',
            'shopify_needs_push' => true,
        ]);

        Http::fake(function ($request) {
            if (str_contains($request->url(), 'orders.json') && $request->method() === 'GET') {
                return Http::response([
                    'orders' => [
                        [
                            'id' => 555,
                            'name' => '#555',
                            'email' => 'yeni@example.com',
                            'note' => 'Shopify notu ezmesin',
                            'financial_status' => 'paid',
                            'fulfillment_status' => 'unfulfilled',
                            'total_price' => '999.00',
                            'currency' => 'TRY',
                            'line_items' => [],
                            'customer' => [
                                'id' => 9,
                                'first_name' => 'Ali',
                                'last_name' => 'Veli',
                                'email' => 'ali@example.com',
                            ],
                        ],
                        [
                            'id' => 777,
                            'name' => '#777',
                            'email' => 'yeni@example.com',
                            'financial_status' => 'paid',
                            'fulfillment_status' => 'unfulfilled',
                            'total_price' => '40.00',
                            'currency' => 'TRY',
                            'line_items' => [
                                ['id' => 1, 'title' => 'Ürün', 'quantity' => 1, 'price' => 40],
                            ],
                            'customer' => [
                                'id' => 11,
                                'first_name' => 'Yeni',
                                'last_name' => 'Müşteri',
                                'email' => 'yeni@example.com',
                            ],
                        ],
                    ],
                ], 200);
            }

            return $this->shopifyHttpResponse($request);
        });

        $result = app(OrderSyncService::class)->sync(10, 'any');

        $existing->refresh();
        $this->assertSame('fulfilled', $existing->fulfillment_status);
        $this->assertSame('Lokal İsim', $existing->customer_name);
        $this->assertSame('Lokal not', $existing->notes);
        $this->assertFalse((bool) $existing->shopify_needs_push);
        $this->assertNotNull($existing->shopify_pushed_at);

        $this->assertDatabaseHas('shopify_orders', [
            'shopify_order_id' => '777',
            'order_number' => '#777',
        ]);

        $this->assertGreaterThanOrEqual(1, $result['pushed'] ?? 0);
        $this->assertDatabaseHas('sync_activities', [
            'type' => 'order_sync',
            'status' => SyncActivity::STATUS_COMPLETED,
        ]);
    }

    public function test_order_detail_sync_button_pushes_and_logs(): void
    {
        $this->configureShopify();
        $user = User::factory()->create(['email_verified_at' => now()]);
        $order = $this->order([
            'fulfillment_status' => 'preparing',
            'shopify_needs_push' => true,
        ]);

        Http::fake(fn ($request) => $this->shopifyHttpResponse($request));

        $this->actingAs($user)
            ->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee('Senkronize et');

        $this->actingAs($user)
            ->post(route('orders.sync-one', $order))
            ->assertRedirect(route('orders.show', $order));

        $order->refresh();
        $this->assertFalse((bool) $order->shopify_needs_push);
        $this->assertNotNull($order->shopify_pushed_at);

        Http::assertSent(function ($request): bool {
            if ($request->method() !== 'PUT' || ! str_contains($request->url(), 'orders/')) {
                return false;
            }
            $tags = (string) data_get($request->data(), 'order.tags');

            return str_contains($tags, 'Hazırlanıyor');
        });

        $this->assertDatabaseHas('sync_activities', [
            'type' => 'order_sync',
            'status' => SyncActivity::STATUS_COMPLETED,
        ]);
    }

    public function test_reverting_fulfilled_cancels_shopify_fulfillments(): void
    {
        $this->configureShopify();
        $user = User::factory()->create(['email_verified_at' => now()]);
        $order = $this->order(['fulfillment_status' => 'fulfilled']);

        $cancelled = false;
        Http::fake(function ($request) use (&$cancelled) {
            $url = $request->url();
            if (str_contains($url, 'access_scopes')) {
                return $this->shopifyHttpResponse($request);
            }
            if (str_contains($url, '/fulfillments/') && str_contains($url, 'cancel')) {
                $cancelled = true;

                return Http::response(['fulfillment' => ['id' => 77, 'status' => 'cancelled']], 200);
            }
            if (str_contains($url, 'fulfillments.json') && $request->method() === 'GET') {
                return Http::response([
                    'fulfillments' => $cancelled ? [] : [['id' => 77, 'status' => 'success']],
                ], 200);
            }
            if (str_contains($url, 'orders/') && $request->method() === 'GET' && ! str_contains($url, 'fulfillment')) {
                return Http::response([
                    'order' => [
                        'id' => 1002,
                        'tags' => 'EtiCart, Kargoya Verildi',
                        'note' => '',
                        'note_attributes' => [],
                        'fulfillment_status' => $cancelled ? 'unfulfilled' : 'fulfilled',
                    ],
                ], 200);
            }

            return $this->shopifyHttpResponse($request);
        });

        $this->actingAs($user)
            ->patch(route('orders.update-status', $order), [
                'fulfillment_status' => 'unfulfilled',
                'payment_status' => 'paid',
            ])
            ->assertRedirect(route('orders.show', $order));

        $this->assertSame('unfulfilled', $order->fresh()->fulfillment_status);

        Http::assertSent(function ($request): bool {
            return $request->method() === 'POST'
                && str_contains($request->url(), 'fulfillments/77/cancel');
        });

        Http::assertSent(function ($request): bool {
            if ($request->method() !== 'PUT' || ! str_contains($request->url(), 'orders/')) {
                return false;
            }
            $tags = (string) data_get($request->data(), 'order.tags');

            return ! str_contains($tags, 'Kargoya Verildi');
        });
    }

    public function test_revert_finds_fulfillment_via_fulfillment_order_when_order_list_empty(): void
    {
        $this->configureShopify();
        $user = User::factory()->create(['email_verified_at' => now()]);
        $order = $this->order(['fulfillment_status' => 'fulfilled']);

        $cancelled = false;
        Http::fake(function ($request) use (&$cancelled) {
            $url = $request->url();
            if (str_contains($url, '/fulfillments/') && str_contains($url, 'cancel')) {
                $cancelled = true;

                return Http::response(['fulfillment' => ['id' => 91, 'status' => 'cancelled']], 200);
            }
            if (str_contains($url, 'fulfillment_orders/') && str_contains($url, 'fulfillments.json')) {
                return Http::response([
                    'fulfillments' => $cancelled ? [] : [['id' => 91, 'status' => 'success']],
                ], 200);
            }
            if (str_contains($url, 'orders/') && str_contains($url, 'fulfillments.json')) {
                return Http::response(['fulfillments' => []], 200);
            }
            if (str_contains($url, 'fulfillment_orders.json')) {
                return Http::response([
                    'fulfillment_orders' => [['id' => 88, 'status' => 'closed']],
                ], 200);
            }
            if (str_contains($url, 'orders/') && $request->method() === 'GET') {
                return Http::response([
                    'order' => [
                        'id' => 1002,
                        'fulfillment_status' => $cancelled ? 'unfulfilled' : 'fulfilled',
                        'tags' => '',
                        'note' => '',
                        'note_attributes' => [],
                    ],
                ], 200);
            }

            return $this->shopifyHttpResponse($request);
        });

        $this->actingAs($user)
            ->patch(route('orders.update-status', $order), [
                'fulfillment_status' => 'preparing',
            ])
            ->assertRedirect(route('orders.show', $order));

        Http::assertSent(function ($request): bool {
            return $request->method() === 'POST'
                && str_contains($request->url(), 'fulfillments/91/cancel');
        });
    }

    public function test_cancelled_status_cancels_shopify_order(): void
    {
        $this->configureShopify();
        $user = User::factory()->create(['email_verified_at' => now()]);
        $order = $this->order(['fulfillment_status' => 'fulfilled']);

        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, '/orders/') && str_contains($url, 'cancel.json')) {
                return Http::response(['order' => ['id' => 1002, 'cancelled_at' => now()->toIso8601String()]], 200);
            }
            if (str_contains($url, 'fulfillments.json') && $request->method() === 'GET') {
                return Http::response(['fulfillments' => []], 200);
            }

            return $this->shopifyHttpResponse($request);
        });

        $this->actingAs($user)
            ->patch(route('orders.update-status', $order), [
                'fulfillment_status' => 'cancelled',
            ])
            ->assertRedirect(route('orders.show', $order));

        Http::assertSent(function ($request): bool {
            return $request->method() === 'POST'
                && str_contains($request->url(), 'orders/1002/cancel.json');
        });
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function order(array $overrides = []): ShopifyOrder
    {
        return ShopifyOrder::query()->create(array_merge([
            'shopify_order_id' => '1002',
            'order_number' => '#1002',
            'customer_name' => 'Ali',
            'customer_email' => 'ali@example.com',
            'total_price' => 90,
            'currency' => 'TRY',
            'payment_status' => 'paid',
            'fulfillment_status' => 'unfulfilled',
            'synced_at' => now(),
        ], $overrides));
    }

    private function configureShopify(): void
    {
        Setting::setValue('shopify_store_url', 'https://test-shop.myshopify.com', 'shopify');
        Setting::setValue('shopify_access_token', 'shpat_test', 'shopify');
    }

    private function shopifyHttpResponse($request): mixed
    {
        $url = $request->url();

        if (str_contains($url, 'access_scopes')) {
            return Http::response([
                'access_scopes' => [
                    ['handle' => 'write_orders'],
                    ['handle' => 'write_fulfillments'],
                    ['handle' => 'read_merchant_managed_fulfillment_orders'],
                    ['handle' => 'write_merchant_managed_fulfillment_orders'],
                ],
            ], 200);
        }

        if (str_contains($url, 'fulfillment_orders')) {
            return Http::response([
                'fulfillment_orders' => [
                    [
                        'id' => 88,
                        'status' => 'open',
                        'line_items' => [
                            ['id' => 501, 'quantity' => 1, 'fulfillable_quantity' => 1],
                        ],
                    ],
                ],
            ], 200);
        }

        if (str_contains($url, '/fulfillments/') && str_contains($url, 'cancel')) {
            return Http::response(['fulfillment' => ['id' => 77, 'status' => 'cancelled']], 200);
        }

        if (str_contains($url, 'graphql.json')) {
            return Http::response(['data' => ['order' => ['fulfillments' => []]]], 200);
        }

        if (str_contains($url, 'fulfillments.json') && $request->method() === 'GET') {
            return Http::response(['fulfillments' => []], 200);
        }

        if (str_contains($url, 'fulfillments.json') && $request->method() === 'POST') {
            return Http::response(['fulfillment' => ['id' => 9]], 201);
        }

        if (str_contains($url, '/cancel.json') && $request->method() === 'POST') {
            return Http::response(['order' => ['id' => 1002, 'cancelled_at' => now()->toIso8601String()]], 200);
        }

        if (str_contains($url, 'orders/') && $request->method() === 'GET') {
            return Http::response([
                'order' => [
                    'id' => 1002,
                    'tags' => '',
                    'note' => '',
                    'note_attributes' => [],
                    'fulfillment_status' => 'unfulfilled',
                    'line_items' => [
                        ['id' => 11, 'quantity' => 1, 'fulfillable_quantity' => 1],
                    ],
                ],
            ], 200);
        }

        if (str_contains($url, 'orders/') && $request->method() === 'PUT') {
            return Http::response(['order' => ['id' => 1002]], 200);
        }

        return Http::response(['orders' => []], 200);
    }
}
