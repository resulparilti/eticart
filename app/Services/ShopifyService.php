<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ShopifyException;
use App\Models\Setting;
use App\Models\ShopifyOrder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ShopifyService
{
    /**
     * Any one of these write scopes is enough to mark an order Fulfilled.
     * write_fulfillments alone is not required for FulfillmentOrders API.
     *
     * @var array<int, string>
     */
    public const FULFILLMENT_SCOPES = [
        'write_merchant_managed_fulfillment_orders',
        'write_assigned_fulfillment_orders',
        'write_third_party_fulfillment_orders',
    ];

    private string $storeUrl;

    private string $accessToken;

    private string $apiVersion;

    private ?int $rateLimitRemaining = null;

    public function __construct()
    {
        $this->storeUrl = rtrim((string) (Setting::getValue('shopify_store_url') ?: config('services.shopify.store_url') ?: ''), '/');
        $this->accessToken = (string) (Setting::getValue('shopify_access_token') ?: config('services.shopify.access_token') ?: '');
        $this->apiVersion = (string) (Setting::getValue('shopify_api_version') ?: config('services.shopify.api_version') ?: '2024-01');
    }

    /**
     * Check whether Shopify credentials are configured.
     */
    public function isConfigured(): bool
    {
        return $this->storeUrl !== '' && $this->accessToken !== '';
    }

    /**
     * Geçersiz (401) token'ı ayarlardan düşürür; bir sonraki OAuth yeni token üretir.
     */
    public function invalidateStoredAccessToken(): void
    {
        $this->accessToken = '';

        try {
            Setting::setValue('shopify_access_token', '', 'shopify', 'Shopify Access Token');
            Cache::forget('eticart.settings');
        } catch (Throwable) {
            // Ayar yazılamasa bile bu istekte token kullanılmasın.
        }
    }

    /**
     * Kayıtlı token Shopify tarafından hâlâ kabul ediliyor mu?
     */
    public function accessTokenIsValid(): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        try {
            $this->testConnection();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Test API connectivity.
     *
     * @return array<string, mixed>
     */
    public function testConnection(): array
    {
        $response = $this->makeRequest('GET', 'shop.json');

        return $response['shop'] ?? $response;
    }

    /**
     * Scopes granted to the current Admin API token.
     *
     * @return array<int, string>
     */
    public function getAccessScopes(): array
    {
        if (! $this->isConfigured()) {
            return [];
        }

        $response = Http::withHeaders([
            'X-Shopify-Access-Token' => $this->accessToken,
            'Accept' => 'application/json',
        ])->timeout(20)->acceptJson()->get($this->shopHost().'/admin/oauth/access_scopes.json');

        if ($response->failed()) {
            throw new ShopifyException(
                'Shopify yetki listesi alınamadı ('.$response->status().').',
                ['body' => $response->json()]
            );
        }

        $scopes = [];
        foreach ($response->json('access_scopes') ?? [] as $row) {
            $handle = is_array($row) ? trim((string) ($row['handle'] ?? '')) : '';
            if ($handle !== '') {
                $scopes[] = $handle;
            }
        }

        return array_values(array_unique($scopes));
    }

    /**
     * @return array<int, string>
     */
    public function missingFulfillmentScopes(?array $granted = null): array
    {
        $granted = $granted ?? $this->getAccessScopes();

        foreach (self::FULFILLMENT_SCOPES as $scope) {
            if (in_array($scope, $granted, true)) {
                return [];
            }
        }

        return ['write_merchant_managed_fulfillment_orders'];
    }

    public function fulfillmentPermissionMessage(array $missing): string
    {
        return 'Shopify siparişi Fulfilled yapılamadı. Token’da '
            .implode(', ', $missing)
            .' yok. Dev Dashboard → Versions → Merchant-managed fulfillment orders (Write) ekleyip '
            .'uygulamayı mağazaya yeniden yükleyin.';
    }

    public function assertCanFulfillOrders(): void
    {
        try {
            $missing = $this->missingFulfillmentScopes();
        } catch (ShopifyException) {
            return;
        }

        if ($missing !== []) {
            throw new ShopifyException($this->fulfillmentPermissionMessage($missing));
        }
    }

    /**
     * Fetch orders from Shopify.
     *
     * @return array{orders: array<int, array<string, mixed>>, next_page_info: ?string}
     */
    /**
     * @param  array{updated_at_min?: string}  $filters
     */
    public function getOrders(int $limit = 50, string $status = 'any', ?string $pageInfo = null, array $filters = []): array
    {
        $query = [
            'limit' => min(max($limit, 1), 250),
            'status' => $status,
        ];

        if ($pageInfo) {
            $query = [
                'limit' => min(max($limit, 1), 250),
                'page_info' => $pageInfo,
            ];
        } elseif (! empty($filters['updated_at_min'])) {
            $query['updated_at_min'] = $filters['updated_at_min'];
        }

        $response = $this->makeRequest('GET', 'orders.json', null, $query);

        return [
            'orders' => $response['orders'] ?? [],
            'next_page_info' => $response['_next_page_info'] ?? null,
        ];
    }

    /**
     * Whether Shopify redacted protected customer PII on this order payload.
     *
     * @param  array<string, mixed>  $order
     */
    public function isCustomerDataRedacted(array $order): bool
    {
        $shipping = is_array($order['shipping_address'] ?? null) ? $order['shipping_address'] : [];
        $billing = is_array($order['billing_address'] ?? null) ? $order['billing_address'] : [];
        $customer = is_array($order['customer'] ?? null) ? $order['customer'] : [];

        $hasEmail = filled($order['email'] ?? null)
            || filled($order['contact_email'] ?? null)
            || filled($customer['email'] ?? null);

        $hasName = filled($shipping['name'] ?? null)
            || filled($shipping['first_name'] ?? null)
            || filled($billing['name'] ?? null)
            || filled($billing['first_name'] ?? null)
            || filled($customer['first_name'] ?? null)
            || filled($customer['last_name'] ?? null);

        $hasAddress = filled($shipping['address1'] ?? null) || filled($billing['address1'] ?? null);

        // Sipariş var ama kimlik alanları boşsa PCD redaction.
        return ! $hasEmail && ! $hasName && ! $hasAddress;
    }

    /**
     * Fetch customers from Shopify Admin API.
     *
     * @return array{customers: array<int, array<string, mixed>>, next_page_info: ?string}
     */
    public function getCustomers(int $limit = 50, ?string $pageInfo = null, ?string $query = null): array
    {
        $params = [
            'limit' => min(max($limit, 1), 250),
        ];

        if ($pageInfo) {
            $params = [
                'limit' => min(max($limit, 1), 250),
                'page_info' => $pageInfo,
            ];
        } elseif ($query) {
            $params['query'] = $query;
        }

        $response = $this->makeRequest('GET', 'customers.json', null, $params);

        return [
            'customers' => $response['customers'] ?? [],
            'next_page_info' => $response['_next_page_info'] ?? null,
        ];
    }

    /**
     * Fetch a single customer.
     *
     * @return array<string, mixed>
     */
    public function getCustomerDetails(string|int $customerId): array
    {
        $response = $this->makeRequest('GET', "customers/{$customerId}.json");

        return $response['customer'] ?? [];
    }

    /**
     * Search customers by email/phone/query.
     *
     * @return array<int, array<string, mixed>>
     */
    public function searchCustomers(string $query, int $limit = 50): array
    {
        $response = $this->makeRequest('GET', 'customers/search.json', null, [
            'query' => $query,
            'limit' => min(max($limit, 1), 250),
        ]);

        return $response['customers'] ?? [];
    }

    /**
     * Fetch a single order.
     *
     * @return array<string, mixed>
     */
    public function getOrderDetails(string|int $orderId): array
    {
        $response = $this->makeRequest('GET', "orders/{$orderId}.json");

        return $response['order'] ?? [];
    }

    /**
     * Fetch a single order; null when Shopify returns 404 (deleted / missing).
     *
     * @return array<string, mixed>|null
     */
    public function findOrder(string|int $orderId): ?array
    {
        try {
            $order = $this->getOrderDetails($orderId);

            return $order !== [] ? $order : null;
        } catch (ShopifyException $e) {
            if ($this->isNotFound($e)) {
                return null;
            }

            throw $e;
        }
    }

    /**
     * REST Returns API; yetki yoksa veya endpoint yoksa boş dizi.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getOrderReturns(string|int $orderId): array
    {
        try {
            $response = $this->makeRequest('GET', "orders/{$orderId}/returns.json");
            $returns = $response['returns'] ?? [];

            return is_array($returns) ? $returns : [];
        } catch (Throwable $e) {
            if ($this->isNotFound($e) || (int) $e->getCode() === 403) {
                return [];
            }

            Log::channel('stack')->warning('Shopify iade listesi alınamadı', [
                'order_id' => $orderId,
                'message' => $e->getMessage(),
            ]);

            return [];
        }
    }

    public function isNotFound(\Throwable $e): bool
    {
        if ($e instanceof ShopifyException && (int) $e->getCode() === 404) {
            return true;
        }

        $status = (int) ($e instanceof ShopifyException ? ($e->context()['status'] ?? 0) : 0);

        return $status === 404 || str_contains($e->getMessage(), '(404)');
    }

    /**
     * Create a product on Shopify.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createProduct(array $data): array
    {
        $response = $this->makeRequest('POST', 'products.json', ['product' => $data]);

        return $response['product'] ?? [];
    }

    /**
     * Update a product on Shopify.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function updateProduct(string|int $productId, array $data): array
    {
        $response = $this->makeRequest('PUT', "products/{$productId}.json", ['product' => $data]);

        return $response['product'] ?? [];
    }

    /**
     * Update a product variant.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function updateProductVariant(string|int $variantId, array $data): array
    {
        $response = $this->makeRequest('PUT', "variants/{$variantId}.json", ['variant' => $data]);

        return $response['variant'] ?? [];
    }

    /**
     * Create a variant on an existing Shopify product.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createProductVariant(string|int $productId, array $data): array
    {
        $response = $this->makeRequest('POST', "products/{$productId}/variants.json", ['variant' => $data]);

        return $response['variant'] ?? [];
    }

    /**
     * Fetch a single variant (includes inventory_item_id).
     *
     * @return array<string, mixed>
     */
    public function getProductVariant(string|int $variantId): array
    {
        $response = $this->makeRequest('GET', "variants/{$variantId}.json");

        return $response['variant'] ?? [];
    }

    /**
     * Mağaza depolarını getir.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getLocations(): array
    {
        $response = $this->makeRequest('GET', 'locations.json');
        $locations = $response['locations'] ?? [];

        return is_array($locations) ? array_values($locations) : [];
    }

    /**
     * Kayıtlı location, yoksa Shopify’daki birincil aktif depo,
     * yoksa verilen inventory item’ın halihazırda bağlı olduğu depo.
     */
    public function resolveLocationId(?string $locationId = null, string|int|null $hintInventoryItemId = null): string
    {
        $locationId = trim((string) ($locationId ?: Setting::getValue('shopify_location_id', '')));
        if ($locationId !== '') {
            return $locationId;
        }

        $chosen = null;
        try {
            foreach ($this->getLocations() as $location) {
                if (! is_array($location) || empty($location['id'])) {
                    continue;
                }
                if (array_key_exists('active', $location) && ! $location['active']) {
                    continue;
                }
                if ($chosen === null) {
                    $chosen = $location;
                }
                if (! empty($location['primary'])) {
                    $chosen = $location;
                    break;
                }
            }
        } catch (ShopifyException $e) {
            Log::channel('stack')->warning('Shopify locations okunamadı', [
                'message' => $e->getMessage(),
            ]);
        }

        if ($chosen === null && $hintInventoryItemId) {
            foreach ($this->getInventoryLevelsForItem($hintInventoryItemId) as $level) {
                if (! empty($level['location_id'])) {
                    $chosen = ['id' => $level['location_id']];
                    break;
                }
            }
        }

        if ($chosen === null) {
            throw new ShopifyException('Shopify location ID tanımlı değil ve mağazada aktif depo bulunamadı. Ayarlar → Shopify → Location ID alanını doldurun.');
        }

        $locationId = (string) $chosen['id'];
        $this->persistLocationId($locationId);

        return $locationId;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getInventoryLevelsForItem(string|int $inventoryItemId): array
    {
        $response = $this->makeRequest('GET', 'inventory_levels.json', null, [
            'inventory_item_ids' => (string) $inventoryItemId,
        ]);
        $levels = $response['inventory_levels'] ?? [];

        return is_array($levels) ? array_values($levels) : [];
    }

    /**
     * Inventory item’ı depoya bağla; zaten bağlıysa sessiz geç.
     */
    public function connectInventoryToLocation(string|int $inventoryItemId, string $locationId, bool $relocate = false): void
    {
        $payload = [
            'location_id' => (int) ($this->numericShopifyId($locationId) ?: $locationId),
            'inventory_item_id' => (int) ($this->numericShopifyId($inventoryItemId) ?: $inventoryItemId),
        ];
        if ($relocate) {
            $payload['relocate_if_necessary'] = true;
        }

        try {
            $this->makeRequest('POST', 'inventory_levels/connect.json', $payload);
        } catch (ShopifyException $e) {
            if ($this->isInventoryAlreadyStocked($e)) {
                return;
            }
            if (! $relocate && $this->isInventoryAtOtherLocation($e)) {
                $this->connectInventoryToLocation($inventoryItemId, $locationId, true);

                return;
            }

            throw $e;
        }
    }

    /**
     * Update inventory level for a variant inventory item.
     *
     * Shopify REST’te variant inventory_quantity salt okunur; stok Inventory API ile yazılır.
     * İlk varyant genelde bir depoya bağlıdır; yeni varyantlar ayrıca connect ister.
     *
     * @return array<string, mixed>
     */
    public function updateInventory(string|int $inventoryItemId, int $quantity, ?string $locationId = null): array
    {
        $locationId = $this->resolveLocationId($locationId, $inventoryItemId);

        try {
            $this->makeRequest('PUT', "inventory_items/{$inventoryItemId}.json", [
                'inventory_item' => [
                    'id' => (int) ($this->numericShopifyId($inventoryItemId) ?: $inventoryItemId),
                    'tracked' => true,
                ],
            ]);
        } catch (ShopifyException $e) {
            Log::channel('stack')->warning('Shopify inventory tracked güncellenemedi', [
                'inventory_item_id' => $inventoryItemId,
                'message' => $e->getMessage(),
            ]);
        }

        try {
            return $this->setInventoryLevel($inventoryItemId, $locationId, $quantity);
        } catch (ShopifyException $e) {
            if (! $this->isInventoryNotStockedAtLocation($e)) {
                throw $e;
            }
        }

        $this->connectInventoryToLocation($inventoryItemId, $locationId);

        return $this->setInventoryLevel($inventoryItemId, $locationId, $quantity);
    }

    /**
     * @return array<string, mixed>
     */
    private function setInventoryLevel(string|int $inventoryItemId, string $locationId, int $quantity): array
    {
        return $this->makeRequest('POST', 'inventory_levels/set.json', [
            'location_id' => (int) ($this->numericShopifyId($locationId) ?: $locationId),
            'inventory_item_id' => (int) ($this->numericShopifyId($inventoryItemId) ?: $inventoryItemId),
            'available' => $quantity,
        ]);
    }

    /**
     * Create a fulfillment for an order (legacy REST).
     *
     * @param  array<int, array<string, mixed>>  $lineItems
     * @return array<string, mixed>
     */
    public function fulfillOrder(string|int $orderId, array $lineItems, ?string $trackingNumber = null, ?string $trackingUrl = null): array
    {
        $payload = [
            'fulfillment' => [
                'line_items' => $lineItems,
                'notify_customer' => false,
            ],
        ];

        if ($trackingNumber) {
            $payload['fulfillment']['tracking_number'] = $trackingNumber;
        }

        if ($trackingUrl) {
            $payload['fulfillment']['tracking_url'] = $trackingUrl;
        }

        $response = $this->makeRequest('POST', "orders/{$orderId}/fulfillments.json", $payload);

        return $response['fulfillment'] ?? [];
    }

    /**
     * Fulfill via FulfillmentOrders API so Shopify order status becomes Fulfilled.
     *
     * @return array<string, mixed>
     */
    public function fulfillOrderWithTracking(
        string|int $orderId,
        string $trackingNumber,
        string $trackingUrl = '',
        string $companyName = 'Kargo'
    ): array {
        $details = $this->getOrderDetails($orderId);
        $remoteStatus = strtolower((string) ($details['fulfillment_status'] ?? ''));
        if (in_array($remoteStatus, ['fulfilled', 'restocked'], true)) {
            return [
                'already_fulfilled' => true,
                'fulfillment_status' => $remoteStatus,
            ];
        }

        $this->assertCanFulfillOrders();

        $fulfillmentOrders = $this->openFulfillmentOrders($orderId);
        $lastError = null;

        if ($fulfillmentOrders !== []) {
            try {
                return $this->createFulfillmentFromOrders(
                    $fulfillmentOrders,
                    $trackingNumber,
                    $trackingUrl,
                    $companyName
                );
            } catch (ShopifyException $e) {
                $lastError = $e;
                Log::channel('stack')->warning('Shopify FulfillmentOrders failed, trying legacy', [
                    'order_id' => $orderId,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        try {
            $lineItems = [];
            foreach ($details['line_items'] ?? [] as $item) {
                $remaining = (int) ($item['fulfillable_quantity'] ?? $item['quantity'] ?? 0);
                if (! empty($item['id']) && $remaining > 0) {
                    $lineItems[] = [
                        'id' => $item['id'],
                        'quantity' => $remaining,
                    ];
                }
            }

            if ($lineItems === []) {
                foreach ($details['line_items'] ?? [] as $item) {
                    if (! empty($item['id'])) {
                        $lineItems[] = [
                            'id' => $item['id'],
                            'quantity' => (int) ($item['quantity'] ?? 1),
                        ];
                    }
                }
            }

            $legacy = $this->fulfillOrder(
                $orderId,
                $lineItems,
                $trackingNumber !== '' ? $trackingNumber : null,
                $trackingUrl !== '' ? $trackingUrl : null
            );
            if ($legacy !== []) {
                return $legacy;
            }
        } catch (ShopifyException $e) {
            $lastError = $e;
            Log::channel('stack')->warning('Shopify legacy fulfill failed', [
                'order_id' => $orderId,
                'message' => $e->getMessage(),
            ]);
        }

        $hint = $lastError?->getMessage() ?? '';
        if (str_contains($hint, '403') || str_contains($hint, 'required permission')) {
            throw new ShopifyException(
                $this->fulfillmentPermissionMessage(self::FULFILLMENT_SCOPES),
                ['order_id' => $orderId],
                403,
                $lastError
            );
        }

        throw new ShopifyException(
            'Shopify sipariş durumu Fulfilled yapılamadı'
            .($hint !== '' ? ': '.$hint : '.'),
            ['order_id' => $orderId],
            0,
            $lastError
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function openFulfillmentOrders(string|int $orderId): array
    {
        $response = $this->makeRequest('GET', "orders/{$orderId}/fulfillment_orders.json");
        $rows = is_array($response['fulfillment_orders'] ?? null) ? $response['fulfillment_orders'] : [];
        $open = [];

        foreach ($rows as $row) {
            if (! is_array($row) || empty($row['id'])) {
                continue;
            }

            $status = strtolower((string) ($row['status'] ?? ''));
            if ($status === 'on_hold') {
                try {
                    $this->makeRequest('POST', "fulfillment_orders/{$row['id']}/release_hold.json");
                    $status = 'open';
                } catch (ShopifyException $e) {
                    Log::channel('stack')->warning('Shopify fulfillment hold release failed', [
                        'fulfillment_order_id' => $row['id'],
                        'message' => $e->getMessage(),
                    ]);
                    continue;
                }
            }

            if (! in_array($status, ['open', 'in_progress', 'scheduled'], true)) {
                continue;
            }

            if (! isset($row['line_items']) || ! is_array($row['line_items']) || $row['line_items'] === []) {
                try {
                    $detail = $this->makeRequest('GET', "fulfillment_orders/{$row['id']}.json");
                    if (is_array($detail['fulfillment_order'] ?? null)) {
                        $row = $detail['fulfillment_order'];
                    }
                } catch (ShopifyException) {
                    // Liste kaydı ile devam.
                }
            }

            $open[] = $row;
        }

        return $open;
    }

    /**
     * @param  array<int, array<string, mixed>>  $fulfillmentOrders
     * @return array<string, mixed>
     */
    private function createFulfillmentFromOrders(
        array $fulfillmentOrders,
        string $trackingNumber,
        string $trackingUrl,
        string $companyName
    ): array {
        $lines = [];
        foreach ($fulfillmentOrders as $row) {
            $entry = ['fulfillment_order_id' => $row['id']];
            $foLineItems = [];
            foreach ($row['line_items'] ?? [] as $lineItem) {
                if (! is_array($lineItem) || empty($lineItem['id'])) {
                    continue;
                }
                $quantity = (int) ($lineItem['fulfillable_quantity'] ?? $lineItem['quantity'] ?? 0);
                if ($quantity > 0) {
                    $foLineItems[] = [
                        'id' => $lineItem['id'],
                        'quantity' => $quantity,
                    ];
                }
            }
            if ($foLineItems !== []) {
                $entry['fulfillment_order_line_items'] = $foLineItems;
            }
            $lines[] = $entry;
        }

        $fulfillment = [
            'line_items_by_fulfillment_order' => $lines,
            'notify_customer' => false,
        ];

        if ($trackingNumber !== '') {
            $fulfillment['tracking_info'] = array_filter([
                'number' => $trackingNumber,
                'url' => $trackingUrl !== '' ? $trackingUrl : null,
                'company' => $companyName !== '' ? $companyName : null,
            ]);
        }

        $response = $this->makeRequest('POST', 'fulfillments.json', ['fulfillment' => $fulfillment]);
        $created = $response['fulfillment'] ?? $response;
        if (! is_array($created) || ($created === [] && ! isset($response['fulfillment']))) {
            throw new ShopifyException('Shopify fulfillment yanıtı boş döndü.');
        }

        return $created;
    }

    /**
     * Update Shopify order tags / note attributes for EtiCart workflow.
     *
     * @param  array{status_label?: string, payment_label?: string, add_tags?: array<int, string>, remove_tags?: array<int, string>, tracking_number?: string, cargo_company?: string, invoice_url?: string|null, note?: string|null}  $workflow
     * @return array<string, mixed>
     */
    public function updateOrderWorkflow(string|int $orderId, array $workflow): array
    {
        $remote = $this->getOrderDetails($orderId);
        $existingTags = array_values(array_filter(array_map('trim', explode(',', (string) ($remote['tags'] ?? '')))));

        foreach ($workflow['remove_tags'] ?? [] as $tag) {
            $existingTags = array_values(array_filter(
                $existingTags,
                static fn (string $item): bool => mb_strtolower($item, 'UTF-8') !== mb_strtolower($tag, 'UTF-8')
            ));
        }

        foreach ($workflow['add_tags'] ?? [] as $tag) {
            $exists = false;
            foreach ($existingTags as $item) {
                if (mb_strtolower($item, 'UTF-8') === mb_strtolower($tag, 'UTF-8')) {
                    $exists = true;
                    break;
                }
            }
            if (! $exists && $tag !== '') {
                $existingTags[] = $tag;
            }
        }

        $noteAttributes = is_array($remote['note_attributes'] ?? null) ? $remote['note_attributes'] : [];
        $noteMap = [];
        foreach ($noteAttributes as $attr) {
            if (is_array($attr) && isset($attr['name'])) {
                $noteMap[(string) $attr['name']] = $attr['value'] ?? '';
            }
        }

        if (! empty($workflow['status_label'])) {
            $noteMap['eticart_status'] = $workflow['status_label'];
        }
        if (! empty($workflow['payment_label'])) {
            $noteMap['eticart_payment'] = $workflow['payment_label'];
        }
        if (! empty($workflow['tracking_number'])) {
            $noteMap['eticart_tracking'] = $workflow['tracking_number'];
        }
        if (! empty($workflow['cargo_company'])) {
            $noteMap['eticart_cargo'] = $workflow['cargo_company'];
        }

        $hasLocalNote = array_key_exists('note', $workflow);
        $sourceNote = $hasLocalNote
            ? (string) ($workflow['note'] ?? '')
            : (string) ($remote['note'] ?? '');

        $invoiceUrl = array_key_exists('invoice_url', $workflow)
            ? trim((string) ($workflow['invoice_url'] ?? ''))
            : null;
        if ($invoiceUrl !== null) {
            $keepInvoiceAttribute = $invoiceUrl !== ''
                && (! $hasLocalNote || ShopifyOrder::noteContainsInvoiceLine($sourceNote));
            if ($keepInvoiceAttribute) {
                $noteMap['Fatura'] = $invoiceUrl;
                $noteMap['eticart_invoice'] = $invoiceUrl;
            } else {
                unset($noteMap['Fatura'], $noteMap['eticart_invoice']);
            }
        }

        $attributes = [];
        foreach ($noteMap as $name => $value) {
            $attributes[] = ['name' => $name, 'value' => $value];
        }

        $orderPayload = [
            'id' => $orderId,
            'tags' => implode(', ', $existingTags),
            'note_attributes' => $attributes,
        ];

        if ($hasLocalNote || $invoiceUrl !== null) {
            if ($invoiceUrl === '') {
                $orderPayload['note'] = (string) (ShopifyOrder::stripInvoiceLines($sourceNote) ?? '');
                $this->deleteOrderInvoiceMetafield($orderId);
            } elseif ($invoiceUrl !== null) {
                // Panel notları kaynak: silinen Fatura satırını yeniden ekleme.
                $orderPayload['note'] = $sourceNote;
                $orderPayload['metafields'] = [[
                    'namespace' => 'eticart',
                    'key' => 'invoice_url',
                    'type' => 'url',
                    'value' => $invoiceUrl,
                ]];
            } else {
                $orderPayload['note'] = $sourceNote;
            }
        }

        $response = $this->makeRequest('PUT', "orders/{$orderId}.json", [
            'order' => $orderPayload,
        ]);

        return $response['order'] ?? $response;
    }

    private function deleteOrderInvoiceMetafield(string|int $orderId): void
    {
        try {
            $response = $this->makeRequest('GET', "orders/{$orderId}/metafields.json", null, [
                'namespace' => 'eticart',
                'key' => 'invoice_url',
            ]);

            foreach ($response['metafields'] ?? [] as $field) {
                $metafieldId = $field['id'] ?? null;
                if ($metafieldId === null) {
                    continue;
                }

                $this->makeRequest('DELETE', 'metafields/'.$metafieldId.'.json');
            }
        } catch (Throwable $e) {
            Log::channel('stack')->warning('Shopify invoice metafield delete failed', [
                'order_id' => $orderId,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Revert Shopify fulfillment status when local status is no longer "kargoya verildi".
     *
     * @return array{action: string, count?: int, already?: bool}
     */
    public function revertShopifyFulfillment(string|int $orderId, string $targetStatus): array
    {
        $targetStatus = strtolower(trim($targetStatus));
        $details = $this->getOrderDetails($orderId);
        $alreadyCancelled = filled($details['cancelled_at'] ?? null);

        if ($targetStatus === 'cancelled') {
            if ($alreadyCancelled) {
                return ['action' => 'order_cancelled', 'already' => true];
            }

            $this->cancelAllFulfillments($orderId);

            try {
                $this->cancelOrder($orderId, [
                    'reason' => 'other',
                    'email' => false,
                    'restock' => true,
                ]);
            } catch (ShopifyException $e) {
                if (! str_contains(mb_strtolower($e->getMessage()), 'already')) {
                    throw $e;
                }
            }

            return ['action' => 'order_cancelled'];
        }

        $remote = strtolower((string) ($details['fulfillment_status'] ?? ''));
        if (in_array($remote, ['', 'null', 'unfulfilled'], true)) {
            return ['action' => 'already_open', 'already' => true, 'count' => 0];
        }

        $cancelled = $this->cancelAllFulfillments($orderId);
        $fresh = $this->getOrderDetails($orderId);
        $after = strtolower((string) ($fresh['fulfillment_status'] ?? ''));

        if (in_array($after, ['fulfilled', 'partial'], true) && $targetStatus !== 'partial') {
            throw new ShopifyException(
                'Shopify siparişi hâlâ gönderildi (Fulfilled). '
                .($cancelled === []
                    ? 'Aktif fulfillment kaydı bulunamadı veya iptal yetkisi yok.'
                    : 'Fulfillment iptali Shopify durumunu değiştirmedi.')
            );
        }

        return [
            'action' => 'fulfillments_cancelled',
            'count' => count($cancelled),
            'already' => $cancelled === [],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function cancelAllFulfillments(string|int $orderId): array
    {
        $fulfillments = $this->listActiveFulfillments($orderId);
        $cancelled = [];

        foreach ($fulfillments as $row) {
            $cancelled[] = $this->cancelOneFulfillment($orderId, $row);
        }

        return $cancelled;
    }

    /**
     * @return array<int, array{id: string, status: string, gid: string}>
     */
    private function listActiveFulfillments(string|int $orderId): array
    {
        $byId = [];

        try {
            $response = $this->makeRequest('GET', "orders/{$orderId}/fulfillments.json");
            foreach ($response['fulfillments'] ?? [] as $row) {
                $this->indexActiveFulfillment($byId, $row);
            }
        } catch (ShopifyException $e) {
            Log::channel('stack')->warning('List order fulfillments failed', [
                'order_id' => $orderId,
                'message' => $e->getMessage(),
            ]);
        }

        try {
            $orders = $this->makeRequest('GET', "orders/{$orderId}/fulfillment_orders.json");
            foreach ($orders['fulfillment_orders'] ?? [] as $fulfillmentOrder) {
                if (! is_array($fulfillmentOrder)) {
                    continue;
                }
                foreach ($fulfillmentOrder['fulfillments'] ?? [] as $row) {
                    $this->indexActiveFulfillment($byId, $row);
                }
                if (empty($fulfillmentOrder['id'])) {
                    continue;
                }
                try {
                    $nested = $this->makeRequest('GET', "fulfillment_orders/{$fulfillmentOrder['id']}/fulfillments.json");
                    foreach ($nested['fulfillments'] ?? [] as $row) {
                        $this->indexActiveFulfillment($byId, $row);
                    }
                } catch (ShopifyException) {
                    // FO kapalıysa veya yetki yoksa diğer kaynaklara bak.
                }
            }
        } catch (ShopifyException $e) {
            Log::channel('stack')->warning('List fulfillment orders failed', [
                'order_id' => $orderId,
                'message' => $e->getMessage(),
            ]);
        }

        if ($byId === []) {
            foreach ($this->graphqlOrderFulfillments($orderId) as $row) {
                $this->indexActiveFulfillment($byId, $row);
            }
        }

        return array_values($byId);
    }

    /**
     * @param  array<string, array{id: string, status: string, gid: string}>  $byId
     */
    private function indexActiveFulfillment(array &$byId, mixed $row): void
    {
        if (! is_array($row)) {
            return;
        }

        $rawId = $row['id'] ?? null;
        $id = $this->numericShopifyId($rawId);
        if ($id === null) {
            return;
        }

        $status = strtolower((string) ($row['status'] ?? 'success'));
        if (in_array($status, ['cancelled', 'canceled', 'error', 'failure'], true)) {
            return;
        }

        $byId[$id] = [
            'id' => $id,
            'status' => $status,
            'gid' => is_string($rawId) && str_contains($rawId, 'gid://')
                ? $rawId
                : 'gid://shopify/Fulfillment/'.$id,
        ];
    }

    private function numericShopifyId(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            return (string) (int) $value;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/(\d+)$/', $value, $matches) === 1) {
            return $matches[1];
        }

        return ctype_digit($value) ? $value : null;
    }

    /**
     * @param  array{id: string, status: string, gid: string}  $fulfillment
     * @return array<string, mixed>
     */
    private function cancelOneFulfillment(string|int $orderId, array $fulfillment): array
    {
        $id = $fulfillment['id'];

        try {
            $result = $this->makeRequest('POST', "fulfillments/{$id}/cancel.json", []);

            return is_array($result['fulfillment'] ?? null) ? $result['fulfillment'] : $result;
        } catch (ShopifyException $first) {
            try {
                $legacy = $this->makeRequest('POST', "orders/{$orderId}/fulfillments/{$id}/cancel.json", []);

                return is_array($legacy['fulfillment'] ?? null) ? $legacy['fulfillment'] : $legacy;
            } catch (ShopifyException $second) {
                $graphql = $this->graphqlFulfillmentCancel($fulfillment['gid']);
                if ($graphql !== []) {
                    return $graphql;
                }

                throw new ShopifyException(
                    'Fulfillment iptal edilemedi: '.$first->getMessage(),
                    ['order_id' => $orderId, 'fulfillment_id' => $id],
                    $first->getCode(),
                    $first
                );
            }
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function graphqlOrderFulfillments(string|int $orderId): array
    {
        $data = $this->graphql(
            <<<'GRAPHQL'
            query OrderFulfillments($id: ID!) {
              order(id: $id) {
                displayFulfillmentStatus
                fulfillments(first: 20) {
                  id
                  status
                }
              }
            }
            GRAPHQL,
            ['id' => 'gid://shopify/Order/'.$orderId]
        );

        $rows = $data['order']['fulfillments'] ?? [];

        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function graphqlFulfillmentCancel(string $gid): array
    {
        $data = $this->graphql(
            <<<'GRAPHQL'
            mutation FulfillmentCancel($id: ID!) {
              fulfillmentCancel(id: $id) {
                fulfillment { id status }
                userErrors { field message }
              }
            }
            GRAPHQL,
            ['id' => $gid]
        );

        $errors = $data['fulfillmentCancel']['userErrors'] ?? [];
        if (is_array($errors) && $errors !== []) {
            $message = (string) ($errors[0]['message'] ?? 'GraphQL fulfillmentCancel hatası');
            throw new ShopifyException($message, ['gid' => $gid]);
        }

        return is_array($data['fulfillmentCancel']['fulfillment'] ?? null)
            ? $data['fulfillmentCancel']['fulfillment']
            : [];
    }

    /**
     * Shopify iade / iade talebi olan siparişlerin REST id eşlemesi.
     *
     * @return array<string, array{return_status: string, financial_status: string}>
     */
    public function getReturnStatusesByOrderId(): array
    {
        $out = [];
        $cursor = null;
        $pages = 0;

        try {
            do {
                $pages++;
                $data = $this->graphql(
                    <<<'GQL'
                    query OrderReturns($first: Int!, $after: String, $query: String!) {
                      orders(first: $first, after: $after, query: $query) {
                        pageInfo { hasNextPage endCursor }
                        edges {
                          node {
                            legacyResourceId
                            name
                            returnStatus
                            displayFinancialStatus
                          }
                        }
                      }
                    }
                    GQL,
                    [
                        'first' => 50,
                        'after' => $cursor,
                        'query' => 'return_status:IN_PROGRESS OR return_status:RETURN_REQUESTED OR return_status:RETURNED OR financial_status:refunded OR financial_status:partially_refunded',
                    ]
                );

                $connection = is_array($data['orders'] ?? null) ? $data['orders'] : [];
                foreach ($connection['edges'] ?? [] as $edge) {
                    $node = is_array($edge['node'] ?? null) ? $edge['node'] : [];
                    $id = (string) ($node['legacyResourceId'] ?? '');
                    if ($id === '') {
                        continue;
                    }

                    $out[$id] = [
                        'return_status' => strtolower((string) ($node['returnStatus'] ?? '')),
                        'financial_status' => strtolower((string) ($node['displayFinancialStatus'] ?? '')),
                    ];
                }

                $hasNext = (bool) ($connection['pageInfo']['hasNextPage'] ?? false);
                $cursor = $connection['pageInfo']['endCursor'] ?? null;
            } while ($hasNext && filled($cursor) && $pages < 10);
        } catch (Throwable $e) {
            Log::channel('stack')->warning('Shopify iade sorgusu atlandı', [
                'message' => $e->getMessage(),
            ]);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    private function graphql(string $query, array $variables = []): array
    {
        if (! $this->isConfigured()) {
            throw new ShopifyException('Shopify API bilgileri yapılandırılmamış.');
        }

        $response = Http::withHeaders([
            'X-Shopify-Access-Token' => $this->accessToken,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->timeout(30)->acceptJson()->post($this->baseUrl().'/graphql.json', [
            'query' => $query,
            'variables' => $variables,
        ]);

        if ($response->failed()) {
            $this->handleErrors($response, 'graphql.json');
        }

        $json = $response->json() ?? [];
        if (! empty($json['errors']) && is_array($json['errors'])) {
            $message = (string) ($json['errors'][0]['message'] ?? 'Shopify GraphQL hatası');
            throw new ShopifyException($message, ['errors' => $json['errors']]);
        }

        return is_array($json['data'] ?? null) ? $json['data'] : [];
    }

    /**
     * Cancel an order.
     *
     * @param  array{reason?: string, email?: bool, restock?: bool}  $options
     * @return array<string, mixed>
     */
    public function cancelOrder(string|int $orderId, array $options = []): array
    {
        $response = $this->makeRequest('POST', "orders/{$orderId}/cancel.json", [
            'reason' => $options['reason'] ?? 'other',
            'email' => (bool) ($options['email'] ?? false),
            'restock' => (bool) ($options['restock'] ?? true),
        ]);

        return $response['order'] ?? $response;
    }

    /**
     * Admin panel product URL.
     */
    public function adminProductUrl(string|int $productId): ?string
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $host = $this->storeUrl;
        if (! Str::startsWith($host, ['http://', 'https://'])) {
            $host = 'https://'.$host;
        }

        return rtrim($host, '/').'/admin/products/'.$productId;
    }

    /**
     * Fetch products.
     *
     * @return array{products: array<int, array<string, mixed>>, next_page_info: ?string}
     */
    public function getProducts(int $limit = 50, ?string $pageInfo = null): array
    {
        $query = ['limit' => min(max($limit, 1), 250)];

        if ($pageInfo) {
            $query['page_info'] = $pageInfo;
        }

        $response = $this->makeRequest('GET', 'products.json', null, $query);

        return [
            'products' => $response['products'] ?? [],
            'next_page_info' => $response['_next_page_info'] ?? null,
        ];
    }

    /**
     * Fetch a single product.
     *
     * @return array<string, mixed>
     */
    public function getProductDetails(string|int $productId): array
    {
        $response = $this->makeRequest('GET', "products/{$productId}.json");

        return $response['product'] ?? [];
    }

    /**
     * @return array<int, array{id: ?string, namespace: string, key: string, value: mixed, type: string}>
     */
    public function getProductMetafields(string|int $productId): array
    {
        try {
            $response = $this->makeRequest('GET', "products/{$productId}/metafields.json", null, [
                'limit' => 250,
            ]);
        } catch (ShopifyException) {
            return [];
        }

        $normalized = [];
        foreach ($response['metafields'] ?? [] as $field) {
            if (! is_array($field)) {
                continue;
            }

            $namespace = trim((string) ($field['namespace'] ?? ''));
            $key = trim((string) ($field['key'] ?? ''));
            if ($namespace === '' || $key === '') {
                continue;
            }

            $normalized[] = [
                'id' => isset($field['id']) ? (string) $field['id'] : null,
                'namespace' => $namespace,
                'key' => $key,
                'value' => $field['value'] ?? null,
                'type' => (string) ($field['type'] ?? $field['value_type'] ?? ''),
            ];
        }

        return $normalized;
    }

    /**
     * @return array<int, array{id: string, title: string, handle: string, kind: string}>
     */
    public function getProductCollections(string|int $productId): array
    {
        $collections = [];
        $seen = [];

        foreach ([
            'custom_collections.json' => ['custom_collections', 'custom'],
            'smart_collections.json' => ['smart_collections', 'smart'],
        ] as $endpoint => [$listKey, $kind]) {
            try {
                $response = $this->makeRequest('GET', $endpoint, null, [
                    'product_id' => $productId,
                    'limit' => 250,
                ]);
            } catch (ShopifyException) {
                continue;
            }

            foreach ($response[$listKey] ?? [] as $collection) {
                if (! is_array($collection) || empty($collection['id'])) {
                    continue;
                }

                $id = (string) $collection['id'];
                if (isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $collections[] = [
                    'id' => $id,
                    'title' => (string) ($collection['title'] ?? ''),
                    'handle' => (string) ($collection['handle'] ?? ''),
                    'kind' => $kind,
                ];
            }
        }

        return $collections;
    }

    /**
     * Remaining rate limit calls if known.
     */
    public function getRateLimitRemaining(): ?int
    {
        return $this->rateLimitRemaining;
    }

    /**
     * Perform an authenticated Shopify Admin API request.
     *
     * @param  array<string, mixed>|null  $data
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function makeRequest(string $method, string $endpoint, ?array $data = null, array $query = []): array
    {
        if (! $this->isConfigured()) {
            throw new ShopifyException('Shopify API bilgileri yapılandırılmamış. Ayarlar veya .env dosyasını kontrol edin.');
        }

        $url = $this->baseUrl().'/'.ltrim($endpoint, '/');
        $attempts = 0;
        $maxAttempts = 6;

        while ($attempts < $maxAttempts) {
            $attempts++;

            try {
                /** @var PendingRequest $client */
                $client = Http::withHeaders([
                    'X-Shopify-Access-Token' => $this->accessToken,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])->timeout(30)->acceptJson();

                /** @var Response $response */
                $response = match (strtoupper($method)) {
                    'GET' => $client->get($url, $query),
                    'POST' => $client->post($url, $data ?? []),
                    'PUT' => $client->put($url, $data ?? []),
                    'DELETE' => $client->delete($url, $data ?? []),
                    default => throw new ShopifyException("Desteklenmeyen HTTP metodu: {$method}"),
                };

                $this->captureRateLimit($response);

                if ($this->shouldRetryStatus($response->status()) && $attempts < $maxAttempts) {
                    $wait = $this->retryWaitSeconds($response, $attempts);
                    Log::channel('stack')->warning('Shopify API retry', [
                        'endpoint' => $endpoint,
                        'status' => $response->status(),
                        'wait' => $wait,
                        'attempt' => $attempts,
                    ]);
                    sleep($wait);
                    continue;
                }

                if ($response->failed()) {
                    $this->handleErrors($response, $endpoint);
                }

                $payload = $response->json() ?? [];
                $nextPageInfo = $this->extractNextPageInfo($response->header('Link'));

                if ($nextPageInfo) {
                    $payload['_next_page_info'] = $nextPageInfo;
                }

                Log::channel('stack')->info('Shopify API success', [
                    'method' => $method,
                    'endpoint' => $endpoint,
                    'status' => $response->status(),
                    'rate_limit_remaining' => $this->rateLimitRemaining,
                ]);

                return is_array($payload) ? $payload : [];
            } catch (ConnectionException $e) {
                Log::channel('stack')->error('Shopify network error', [
                    'endpoint' => $endpoint,
                    'attempt' => $attempts,
                    'message' => $e->getMessage(),
                ]);

                if ($attempts >= $maxAttempts) {
                    throw new ShopifyException('Shopify API ağ hatası: '.$e->getMessage(), [], 0, $e);
                }

                sleep($attempts);
            }
        }

        throw new ShopifyException('Shopify API isteği başarısız oldu.');
    }

    private function shouldRetryStatus(int $status): bool
    {
        return $status === 409 || $status === 429;
    }

    private function retryWaitSeconds(Response $response, int $attempt): int
    {
        if ($response->status() === 429) {
            return max((int) ($response->header('Retry-After') ?: 2), 1);
        }

        return min(16, 2 ** max($attempt - 1, 0));
    }

    /**
     * Build Admin API base URL.
     */
    private function shopHost(): string
    {
        $host = $this->storeUrl;

        if (! Str::startsWith($host, ['http://', 'https://'])) {
            $host = 'https://'.$host;
        }

        return rtrim($host, '/');
    }

    private function baseUrl(): string
    {
        return $this->shopHost().'/admin/api/'.$this->apiVersion;
    }

    /**
     * Parse and store remaining rate limit.
     */
    private function captureRateLimit(Response $response): void
    {
        $header = $response->header('X-Shopify-Shop-Api-Call-Limit');

        if (! $header || ! str_contains($header, '/')) {
            return;
        }

        [$used, $max] = array_map('intval', explode('/', $header, 2));
        $this->rateLimitRemaining = max($max - $used, 0);
    }

    /**
     * Extract page_info from Shopify Link header.
     */
    private function extractNextPageInfo(?string $linkHeader): ?string
    {
        if (! $linkHeader) {
            return null;
        }

        foreach (explode(',', $linkHeader) as $part) {
            if (! str_contains($part, 'rel="next"')) {
                continue;
            }

            if (preg_match('/page_info=([^&>]+)/', $part, $matches)) {
                return urldecode($matches[1]);
            }
        }

        return null;
    }

    /**
     * Convert failed responses into exceptions.
     */
    private function handleErrors(Response $response, string $endpoint): void
    {
        $body = $response->json();
        $raw = is_array($body)
            ? ($body['errors'] ?? $body['error'] ?? $body)
            : $response->body();
        $message = is_array($raw)
            ? (string) json_encode($raw, JSON_UNESCAPED_UNICODE)
            : (string) $raw;

        Log::channel('stack')->error('Shopify API error', [
            'endpoint' => $endpoint,
            'status' => $response->status(),
            'body' => $body,
        ]);

        if ($response->status() === 401) {
            $this->invalidateStoredAccessToken();
        }

        throw new ShopifyException(
            "Shopify API hatası ({$response->status()}): {$message}",
            [
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'body' => $body,
            ],
            $response->status()
        );
    }

    private function persistLocationId(string $locationId): void
    {
        try {
            Setting::setValue('shopify_location_id', $locationId, 'shopify', 'Shopify Location ID');
        } catch (Throwable) {
            // Ayar yazılamasa bile bu istekte bulunan depo kullanılsın.
        }
    }

    private function isInventoryAlreadyStocked(ShopifyException $e): bool
    {
        $haystack = mb_strtolower($e->getMessage().' '.json_encode($e->context(), JSON_UNESCAPED_UNICODE));

        return str_contains($haystack, 'already stocked')
            || str_contains($haystack, 'already connected')
            || str_contains($haystack, 'already been activated');
    }

    private function isInventoryNotStockedAtLocation(ShopifyException $e): bool
    {
        $haystack = mb_strtolower($e->getMessage().' '.json_encode($e->context(), JSON_UNESCAPED_UNICODE));

        return str_contains($haystack, 'not stocked at')
            || str_contains($haystack, 'not stocked at the location')
            || str_contains($haystack, 'not found for this item')
            || str_contains($haystack, 'does not have inventory levels');
    }

    private function isInventoryAtOtherLocation(ShopifyException $e): bool
    {
        $haystack = mb_strtolower($e->getMessage().' '.json_encode($e->context(), JSON_UNESCAPED_UNICODE));

        return str_contains($haystack, 'already stocked at a location')
            || str_contains($haystack, 'stocked at another location')
            || str_contains($haystack, 'relocate');
    }
}
