<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ShopifyException;
use App\Models\AdminNotification;
use App\Models\ShopifyCustomer;
use App\Models\ShopifyOrder;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

class CustomerSyncService
{
    public function __construct(
        private readonly ShopifyService $shopifyService,
        private readonly SyncActivityTracker $activityTracker
    ) {
    }

    /**
     * Pull customers from Shopify Admin API (paginated).
     *
     * @return array{synced: int, errors: int, pages: int, message: string}
     */
    public function syncFromShopify(int $pageSize = 50, int $maxPages = 40): array
    {
        if (! $this->shopifyService->isConfigured()) {
            throw new ShopifyException('Shopify API bilgileri yapılandırılmamış.');
        }

        $this->activityTracker->markRunning('Shopify müşterileri çekiliyor…');

        $synced = 0;
        $errors = 0;
        $pages = 0;
        $pageInfo = null;

        do {
            $pages++;
            try {
                $result = $this->shopifyService->getCustomers($pageSize, $pageInfo);
            } catch (ShopifyException $e) {
                $this->activityTracker->fail($e->getMessage(), $e);
                throw $e;
            }

            $customers = $result['customers'] ?? [];
            $pageInfo = $result['next_page_info'] ?? null;

            $this->activityTracker->log('info', "Sayfa {$pages}: ".count($customers).' müşteri.');

            foreach ($customers as $payload) {
                try {
                    $this->upsertFromShopifyPayload($payload);
                    $synced++;
                    $this->activityTracker->progress($synced, null, "{$synced} müşteri işlendi…");
                } catch (Throwable $e) {
                    $errors++;
                    $this->activityTracker->log('error', 'Müşteri kaydı başarısız: '.$e->getMessage());
                    Log::channel('stack')->error('Customer upsert failed', [
                        'message' => $e->getMessage(),
                        'customer_id' => $payload['id'] ?? null,
                    ]);
                }
            }
        } while ($pageInfo && $pages < $maxPages);

        $message = "{$synced} müşteri Shopify’dan senkronize edildi"
            .($errors ? ", {$errors} hata" : '')
            .'.';

        $this->activityTracker->complete($message, $synced, $errors, ['pages' => $pages]);

        return [
            'synced' => $synced,
            'errors' => $errors,
            'pages' => $pages,
            'message' => $message,
        ];
    }

    /**
     * Build / refresh customers from local Shopify orders (fallback when API PII is limited).
     *
     * @return array{synced: int, linked: int, message: string}
     */
    public function syncFromLocalOrders(): array
    {
        $this->activityTracker->markRunning('Siparişlerden müşteri kayıtları oluşturuluyor…');

        $synced = 0;
        $linked = 0;
        $orders = ShopifyOrder::query()->orderBy('id')->get();
        $this->activityTracker->setTotal($orders->count());

        foreach ($orders as $index => $order) {
            $customer = $this->upsertFromOrder($order);
            if ($customer) {
                $synced++;
                if ((int) $order->customer_id !== (int) $customer->id) {
                    $order->update(['customer_id' => $customer->id]);
                    $linked++;
                } elseif (! $order->customer_id) {
                    $order->update(['customer_id' => $customer->id]);
                    $linked++;
                }
            }

            $this->activityTracker->progress($index + 1, $orders->count(), 'Sipariş '.($index + 1).'/'.$orders->count());
        }

        $this->recalculateOrderStats();

        $message = "Siparişlerden {$synced} müşteri güncellendi, {$linked} sipariş bağlandı.";
        $this->activityTracker->complete($message, $synced, 0, ['linked' => $linked]);

        return [
            'synced' => $synced,
            'linked' => $linked,
            'message' => $message,
        ];
    }

    /**
     * Full refresh: Shopify API then local order linking/stats.
     *
     * @return array{synced: int, errors: int, linked: int, message: string}
     */
    public function syncAll(): array
    {
        $synced = 0;
        $errors = 0;
        $apiMessage = '';

        try {
            $apiResult = $this->syncFromShopify();
            $synced = (int) ($apiResult['synced'] ?? 0);
            $errors = (int) ($apiResult['errors'] ?? 0);
            $apiMessage = (string) ($apiResult['message'] ?? '');
        } catch (ShopifyException $e) {
            $this->activityTracker->log('warning', 'Shopify müşteri listesi alınamadı: '.$e->getMessage());
            $this->activityTracker->log('info', 'Yerel siparişlerden müşteri derleniyor…');
            if ($this->activityTracker->current()?->status === 'failed') {
                $this->activityTracker->current()?->update([
                    'status' => 'running',
                    'finished_at' => null,
                    'message' => 'Siparişlerden müşteri derleniyor…',
                ]);
            }
            $local = $this->syncFromLocalOrders();
            $synced = (int) ($local['synced'] ?? 0);
            $apiMessage = (string) ($local['message'] ?? '');

            return [
                'synced' => $synced,
                'errors' => 1,
                'linked' => (int) ($local['linked'] ?? 0),
                'message' => $apiMessage.' (Shopify API uyarısı: '.$e->getMessage().')',
            ];
        }

        $linked = 0;
        foreach (ShopifyOrder::query()->orderBy('id')->cursor() as $order) {
            $customer = $this->upsertFromOrder($order);
            if ($customer && (int) ($order->customer_id ?? 0) !== (int) $customer->id) {
                $order->update(['customer_id' => $customer->id]);
                $linked++;
            }
        }
        $this->recalculateOrderStats();
        $this->activityTracker->log('info', "Sipariş bağlantısı: {$linked} kayıt güncellendi.");

        return [
            'synced' => $synced,
            'errors' => $errors,
            'linked' => $linked,
            'message' => trim($apiMessage.' Sipariş bağlantısı: '.$linked.'.'),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function upsertFromShopifyPayload(array $payload): ShopifyCustomer
    {
        $remoteId = isset($payload['id']) ? (string) $payload['id'] : null;
        $defaultAddress = is_array($payload['default_address'] ?? null) ? $payload['default_address'] : [];
        $addresses = is_array($payload['addresses'] ?? null) ? $payload['addresses'] : [];

        $first = (string) ($payload['first_name'] ?? $defaultAddress['first_name'] ?? '');
        $last = (string) ($payload['last_name'] ?? $defaultAddress['last_name'] ?? '');
        $full = trim($first.' '.$last);
        if ($full === '' && filled($defaultAddress['name'] ?? null)) {
            $full = (string) $defaultAddress['name'];
        }

        $email = $this->normalizeEmail($payload['email'] ?? null);
        $phone = $this->normalizePhone($payload['phone'] ?? $defaultAddress['phone'] ?? null);

        $addressParts = array_filter([
            $defaultAddress['company'] ?? null,
            $defaultAddress['address1'] ?? null,
            $defaultAddress['address2'] ?? null,
        ], static fn ($v) => filled($v));

        $tags = $payload['tags'] ?? [];
        if (is_string($tags)) {
            $tags = array_values(array_filter(array_map('trim', explode(',', $tags))));
        }

        $attributes = [
            'email' => $email,
            'phone' => $phone,
            'first_name' => $first !== '' ? $first : null,
            'last_name' => $last !== '' ? $last : null,
            'full_name' => $full !== '' ? $full : ($email ?: $phone),
            'company' => $defaultAddress['company'] ?? null,
            'address' => $addressParts !== [] ? implode(', ', $addressParts) : null,
            'city' => $defaultAddress['city'] ?? null,
            'province' => $defaultAddress['province'] ?? null,
            'country' => $defaultAddress['country'] ?? null,
            'zip' => $defaultAddress['zip'] ?? null,
            'orders_count' => (int) ($payload['orders_count'] ?? 0),
            'total_spent' => (float) ($payload['total_spent'] ?? 0),
            'currency' => $payload['currency'] ?? null,
            'tax_exempt' => (bool) ($payload['tax_exempt'] ?? false),
            'verified_email' => (bool) ($payload['verified_email'] ?? false),
            'state' => $payload['state'] ?? null,
            'tags' => $tags,
            'addresses' => $addresses !== [] ? $addresses : ($defaultAddress !== [] ? [$defaultAddress] : null),
            'raw' => $payload,
            'note' => $payload['note'] ?? null,
            'shopify_created_at' => isset($payload['created_at']) ? Carbon::parse($payload['created_at']) : null,
            'shopify_updated_at' => isset($payload['updated_at']) ? Carbon::parse($payload['updated_at']) : null,
            'last_sync' => now(),
        ];

        if ($remoteId) {
            $customer = ShopifyCustomer::query()->updateOrCreate(
                ['shopify_customer_id' => $remoteId],
                $attributes
            );
        } else {
            $customer = $this->findOrCreateByIdentity($email, $phone, $attributes);
        }

        $this->notifyIfNew($customer);

        return $customer;
    }

    /**
     * Upsert customer from a local order row / extracted fields.
     */
    public function upsertFromOrder(ShopifyOrder $order, ?array $orderPayload = null): ?ShopifyCustomer
    {
        $remoteCustomerId = null;
        if ($orderPayload && isset($orderPayload['customer']['id'])) {
            $remoteCustomerId = (string) $orderPayload['customer']['id'];
        }

        $email = $this->normalizeEmail($order->customer_email);
        $phone = $this->normalizePhone($order->customer_phone);
        $name = trim((string) ($order->customer_name ?? ''));

        if (! $remoteCustomerId && ! $email && ! $phone && ($name === '' || $name === 'Misafir')) {
            return null;
        }

        $nameParts = preg_split('/\s+/', $name, 2) ?: [];
        $attributes = [
            'email' => $email,
            'phone' => $phone,
            'first_name' => $nameParts[0] ?? null,
            'last_name' => $nameParts[1] ?? null,
            'full_name' => $name !== '' && $name !== 'Misafir' ? $name : ($email ?: $phone),
            'address' => $order->shipping_address,
            'city' => $order->shipping_city,
            'currency' => $order->currency,
            'last_sync' => now(),
        ];

        if ($order->shopify_created_at) {
            $attributes['last_order_at'] = $order->shopify_created_at;
        }

        if ($remoteCustomerId) {
            $customer = ShopifyCustomer::query()->updateOrCreate(
                ['shopify_customer_id' => $remoteCustomerId],
                array_filter($attributes, static fn ($v) => $v !== null && $v !== '')
            );
        } else {
            $customer = $this->findOrCreateByIdentity($email, $phone, $attributes);
        }

        return $customer;
    }

    /**
     * Recalculate orders_count / total_spent from local orders.
     */
    public function recalculateOrderStats(): void
    {
        $stats = ShopifyOrder::query()
            ->selectRaw('customer_id, COUNT(*) as cnt, COALESCE(SUM(total_price),0) as spent, MAX(shopify_created_at) as last_at')
            ->whereNotNull('customer_id')
            ->groupBy('customer_id')
            ->get();

        foreach ($stats as $row) {
            ShopifyCustomer::query()->whereKey($row->customer_id)->update([
                'orders_count' => (int) $row->cnt,
                'total_spent' => (float) $row->spent,
                'last_order_at' => $row->last_at,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function findOrCreateByIdentity(?string $email, ?string $phone, array $attributes): ShopifyCustomer
    {
        $query = ShopifyCustomer::query();

        if ($email) {
            $existing = (clone $query)->where('email', $email)->first();
            if ($existing) {
                $existing->update(array_filter($attributes, static fn ($v) => $v !== null && $v !== ''));

                return $existing->fresh();
            }
        }

        if ($phone) {
            $existing = (clone $query)->where('phone', $phone)->first();
            if ($existing) {
                $existing->update(array_filter($attributes, static fn ($v) => $v !== null && $v !== ''));

                return $existing->fresh();
            }
        }

        return ShopifyCustomer::query()->create($attributes);
    }

    private function notifyIfNew(ShopifyCustomer $customer): void
    {
        if (! $customer->wasRecentlyCreated) {
            return;
        }

        $url = \Illuminate\Support\Facades\Route::has('customers.show')
            ? route('customers.show', $customer)
            : null;

        app(AdminNotificationService::class)->notify(
            AdminNotification::TYPE_CUSTOMER_CREATED,
            'Yeni müşteri: '.($customer->full_name ?: $customer->email ?: $customer->phone),
            $customer->email ?: $customer->phone,
            $url,
            $customer
        );
    }

    private function normalizeEmail(mixed $email): ?string
    {
        $email = is_string($email) ? trim(mb_strtolower($email)) : '';

        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    private function normalizePhone(mixed $phone): ?string
    {
        if (! is_string($phone) && ! is_numeric($phone)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', (string) $phone);
        if ($digits === null || strlen($digits) < 7) {
            return null;
        }

        return $digits;
    }
}
