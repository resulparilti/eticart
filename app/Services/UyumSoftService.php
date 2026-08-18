<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\UyumSoftException;
use App\Models\Setting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class UyumSoftService
{
    private string $username;

    private string $password;

    private string $baseUrl;

    private string $warehouseCode;

    private string $branchCode;

    private bool $isCloudApi = false;

    private ?string $accessToken = null;

    private ?string $uyumSecretKey = null;

    public function __construct()
    {
        $this->username = $this->resolveCredential('uyumsoft_api_user', 'services.uyumsoft.username');
        $this->password = $this->resolveCredential('uyumsoft_api_password', 'services.uyumsoft.password');
        $this->baseUrl = rtrim(
            $this->resolveCredential('uyumsoft_base_url', 'services.uyumsoft.base_url', 'https://api.uyumsoft.com/api/v1'),
            '/'
        );
        $this->warehouseCode = $this->resolveCredential('uyumsoft_warehouse_id', 'services.uyumsoft.warehouse_id');
        $this->branchCode = $this->resolveCredential('uyumsoft_branch_code', 'services.uyumsoft.branch_code');
        $this->isCloudApi = str_contains(strtolower($this->baseUrl), 'uyumcloud.com');
    }

    /**
     * Check whether UyumSoft credentials are configured.
     */
    public function isConfigured(): bool
    {
        return $this->username !== '' && $this->password !== '' && $this->baseUrl !== '';
    }

    /**
     * Whether tenant uses UyumCloud (Armoni) API.
     */
    public function usesCloudApi(): bool
    {
        return $this->isCloudApi;
    }

    /**
     * Test API connectivity / authentication.
     *
     * @return array<string, mixed>
     */
    public function testConnection(): array
    {
        $this->authenticate(true);

        if ($this->isCloudApi && $this->branchCode !== '') {
            $this->cloudRequest('INV/GetItemList', [
                'value' => [
                    'branchCode' => $this->branchCode,
                    'whouseCode' => $this->warehouseCode !== '' ? $this->warehouseCode : null,
                    'dontWants' => 'ItemEditor,ItemLang',
                ],
                'pageIndex' => 0,
                'pageSize' => 1,
            ]);
        } elseif (! $this->isCloudApi) {
            $this->makeLegacyRequest('GET', 'products', null, ['limit' => 1, 'offset' => 0]);
        }

        return [
            'ok' => true,
            'mode' => $this->isCloudApi ? 'uyumcloud' : 'legacy',
            'warehouse_code' => $this->warehouseCode,
            'branch_code' => $this->branchCode,
            'base_url' => $this->baseUrl,
        ];
    }

    /**
     * Fetch products from UyumSoft.
     *
     * @param  array<string, mixed>  $filter
     * @return array{items: array<int, array<string, mixed>>, total: int}
     */
    public function getProducts(int $limit = 50, int $offset = 0, array $filter = []): array
    {
        if ($this->isCloudApi) {
            return $this->getCloudProducts($limit, $offset, $filter);
        }

        $query = array_merge([
            'limit' => $limit,
            'offset' => $offset,
            'pageSize' => $limit,
            'page' => (int) floor($offset / max($limit, 1)) + 1,
        ], $filter);

        if ($this->warehouseCode !== '') {
            $query['warehouseId'] = $this->warehouseCode;
            $query['depoId'] = $this->warehouseCode;
        }

        $response = $this->makeLegacyRequest('GET', 'products', null, $query);

        return [
            'items' => $this->extractList($response, ['products', 'items', 'data', 'result']),
            'total' => (int) ($response['total'] ?? $response['totalCount'] ?? count($response['products'] ?? $response['items'] ?? [])),
        ];
    }

    /**
     * Fetch a single product.
     *
     * @return array<string, mixed>
     */
    public function getProductDetails(string|int $productId): array
    {
        if ($this->isCloudApi) {
            $response = $this->cloudRequest('INV/GetItem', [
                'value' => [
                    'code' => (string) $productId,
                ],
            ]);

            $items = $this->extractCloudItems($response);

            return $items[0] ?? [];
        }

        $response = $this->makeLegacyRequest('GET', "products/{$productId}");

        return $this->extractItem($response, ['product', 'data', 'result', 'item']);
    }

    /**
     * Fetch stock levels.
     *
     * @param  array<string, mixed>  $filter
     * @return array<int, array<string, mixed>>
     */
    public function getStocks(array $filter = []): array
    {
        if ($this->isCloudApi) {
            // Normalize için barkod + depo detay alanları korunmalı.
            $result = $this->getCloudProducts(250, 0, $filter);

            return $result['items'];
        }

        $query = $filter;

        if ($this->warehouseCode !== '') {
            $query['warehouseId'] = $this->warehouseCode;
            $query['depoId'] = $this->warehouseCode;
        }

        $response = $this->makeLegacyRequest('GET', 'stocks', null, $query);

        return $this->extractList($response, ['stocks', 'items', 'data', 'result']);
    }

    /**
     * Update stock for a product.
     *
     * @return array<string, mixed>
     */
    public function updateStock(string|int $productId, int $quantity): array
    {
        if ($this->isCloudApi) {
            throw new UyumSoftException('UyumCloud API üzerinden stok güncelleme henüz desteklenmiyor.');
        }

        return $this->makeLegacyRequest('PUT', "stocks/{$productId}", [
            'quantity' => $quantity,
            'stock' => $quantity,
            'warehouseId' => $this->warehouseCode,
            'depoId' => $this->warehouseCode,
        ]);
    }

    public function warehouseCode(): string
    {
        return $this->warehouseCode;
    }

    public function branchCode(): string
    {
        return $this->branchCode;
    }

    public function companyCode(): string
    {
        $code = trim((string) Setting::getValue('uyumsoft_co_code', ''));

        return $code !== '' ? $code : $this->branchCode;
    }

    public function unitCode(): string
    {
        $code = trim((string) Setting::getValue('uyumsoft_unit_code', 'ADET'));

        return $code !== '' ? $code : 'ADET';
    }

    /**
     * Create a sales order in UyumSoft / UyumCloud.
     *
     * @param  array<string, mixed>  $order
     * @return array<string, mixed>
     */
    public function createSalesOrder(array $order): array
    {
        if ($this->isCloudApi) {
            return $this->cloudRequestFirst(
                ['PSM/InsertOrderM', 'PSM/SaveOrderM', 'PSM/SaveOrder', 'SLS/SaveOrderM'],
                [
                    'value' => $order,
                ]
            );
        }

        return $this->makeLegacyRequest('POST', 'orders', $order);
    }

    /**
     * Find an existing sales order by document / Shopify number.
     *
     * @return array<string, mixed>|null
     */
    public function findSalesOrder(string $docNo, string $shopifyOrderNumber): ?array
    {
        $needles = array_values(array_unique(array_filter([
            $docNo,
            $shopifyOrderNumber,
            ltrim($shopifyOrderNumber, '#'),
        ])));

        if ($this->isCloudApi) {
            foreach ($needles as $needle) {
                $response = $this->cloudRequestFirst(
                    ['PSM/GetOrderM', 'PSM/GetOrderMList', 'PSM/GetOrderList'],
                    [
                        'value' => array_filter([
                            'branchCode' => $this->branchCode !== '' ? $this->branchCode : null,
                            'coCode' => $this->companyCode() !== '' ? $this->companyCode() : null,
                            'docNo' => $needle,
                        ]),
                        'pageIndex' => 0,
                        'pageSize' => 20,
                    ],
                    true
                );

                foreach ($this->extractCloudItems($response) as $row) {
                    if ($this->recordMatchesNeedles($row, $needles)) {
                        return $row;
                    }
                }
            }

            return null;
        }

        foreach ($needles as $needle) {
            $response = $this->makeLegacyRequest('GET', 'orders', null, ['docNo' => $needle, 'orderNo' => $needle]);
            $items = $this->extractList($response, ['orders', 'items', 'data', 'result']);
            if ($items !== []) {
                return $items[0];
            }
        }

        return null;
    }

    /**
     * Fetch invoices in a date range.
     *
     * @param  array<string, mixed>  $filter
     * @return array<int, array<string, mixed>>
     */
    public function getInvoices(string $dateFrom, string $dateTo, array $filter = []): array
    {
        if ($this->isCloudApi) {
            $response = $this->cloudRequestFirst(
                ['PSM/GetInvoice', 'FIN/GetInvoiceMList', 'FIN/GetInvoiceList', 'PSM/GetInvoiceMList'],
                [
                    'value' => array_filter([
                        'branchCode' => $this->branchCode !== '' ? $this->branchCode : null,
                        'coCode' => $this->companyCode() !== '' ? $this->companyCode() : null,
                        'startDate' => $dateFrom,
                        'endDate' => $dateTo,
                        'docDate1' => $dateFrom,
                        'docDate2' => $dateTo,
                        'docNo' => $filter['docNo'] ?? null,
                    ]),
                    'pageIndex' => 0,
                    'pageSize' => 100,
                ],
                true
            );

            return $this->extractCloudItems($response);
        }

        $response = $this->makeLegacyRequest('GET', 'invoices', null, array_merge([
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'startDate' => $dateFrom,
            'endDate' => $dateTo,
        ], $filter));

        return $this->extractList($response, ['invoices', 'items', 'data', 'result']);
    }

    /**
     * Find an invoice that belongs to a Shopify / ERP order.
     *
     * @return array<string, mixed>|null
     */
    public function findInvoiceForOrder(string $docNo, string $shopifyOrderNumber, ?string $uyumsoftOrderId = null): ?array
    {
        $needles = array_values(array_unique(array_filter([
            $docNo,
            $shopifyOrderNumber,
            ltrim($shopifyOrderNumber, '#'),
            $uyumsoftOrderId,
        ])));

        $from = now()->subDays(90)->toDateString();
        $to = now()->addDay()->toDateString();

        foreach ($needles as $needle) {
            $invoices = $this->getInvoices($from, $to, ['docNo' => $needle]);
            foreach ($invoices as $invoice) {
                if ($this->recordMatchesNeedles($invoice, $needles)) {
                    return $invoice;
                }
            }
        }

        foreach ($this->getInvoices($from, $to) as $invoice) {
            if ($this->recordMatchesNeedles($invoice, $needles)) {
                return $invoice;
            }
        }

        return null;
    }

    /**
     * Fetch invoice details.
     *
     * @return array<string, mixed>
     */
    public function getInvoiceDetails(string|int $invoiceId): array
    {
        if ($this->isCloudApi) {
            $response = $this->cloudRequestFirst(
                    ['PSM/GetInvoice', 'FIN/GetInvoiceM', 'FIN/GetInvoice'],
                [
                    'value' => [
                        'id' => $invoiceId,
                        'invoiceId' => $invoiceId,
                    ],
                ],
                true
            );

            $items = $this->extractCloudItems($response);

            return $items[0] ?? (is_array($response['result'] ?? null) ? $response['result'] : []);
        }

        $response = $this->makeLegacyRequest('GET', "invoices/{$invoiceId}");

        return $this->extractItem($response, ['invoice', 'data', 'result', 'item']);
    }

    /**
     * Download invoice PDF bytes when UyumSoft exposes it.
     */
    public function getInvoicePdf(string|int $invoiceId): ?string
    {
        if ($this->isCloudApi) {
            try {
                $response = $this->cloudRequestFirst(
                    ['FIN/GetInvoicePdf', 'FIN/GetInvoiceMPdf', 'GNL/GetDocumentPdf'],
                    [
                        'value' => [
                            'id' => $invoiceId,
                            'invoiceId' => $invoiceId,
                        ],
                    ],
                    true
                );
            } catch (UyumSoftException) {
                return null;
            }

            $result = is_array($response['result'] ?? null) ? $response['result'] : $response;
            $encoded = $result['pdf']
                ?? $result['file']
                ?? $result['content']
                ?? $result['fileContents']
                ?? $result['data']
                ?? null;

            if (is_string($encoded) && $encoded !== '') {
                $decoded = base64_decode($encoded, true);

                return $decoded !== false ? $decoded : $encoded;
            }

            return null;
        }

        try {
            $response = $this->makeLegacyRequest('GET', "invoices/{$invoiceId}/pdf");
        } catch (UyumSoftException) {
            return null;
        }

        $encoded = $response['pdf'] ?? $response['content'] ?? $response['data'] ?? null;

        if (is_string($encoded) && $encoded !== '') {
            $decoded = base64_decode($encoded, true);

            return $decoded !== false ? $decoded : $encoded;
        }

        return null;
    }

    /**
     * Normalize a raw UyumSoft product payload into a local-friendly structure.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function normalizeProduct(array $payload): array
    {
        $sku = (string) ($payload['itemCode']
            ?? $payload['sku']
            ?? $payload['stockCode']
            ?? $payload['stokKodu']
            ?? $payload['code']
            ?? '');

        $id = (string) ($payload['itemCode']
            ?? $payload['id']
            ?? $payload['productId']
            ?? $payload['ItemId']
            ?? $payload['itemId']
            ?? $sku);

        $title = (string) ($payload['itemName']
            ?? $payload['title']
            ?? $payload['name']
            ?? $payload['productName']
            ?? $payload['stokAdi']
            ?? $payload['description']
            ?? $sku);

        $price = (float) ($payload['price']
            ?? $payload['salePrice']
            ?? $payload['unitPrice']
            ?? $payload['original_price']
            ?? $payload['fiyat']
            ?? data_get($payload, 'itemPriceList.0.unitPriceTra')
            ?? data_get($payload, 'itemPriceList.0.unitPrice')
            ?? data_get($payload, 'priceList.0.unitPrice')
            ?? data_get($payload, 'priceList.0.price')
            ?? 0);

        // GetItemList içindeki bwhItemList ürün toplamıdır; varyant kırılımı GetBwhItemDetail ile gelir.
        if ($this->isCloudApi && empty($payload['bwhItemDetailList'])) {
            $detailRows = $this->fetchVariantWarehouseStocks($payload, $sku);
            if ($detailRows !== []) {
                $payload['bwhItemDetailList'] = $detailRows;
            }
        }

        $variantInfo = $this->buildVariantInfo($payload, $sku, $price);
        $variantStockSum = (int) ($variantInfo['stock_total'] ?? 0);

        $primaryBarcode = (string) ($variantInfo['variants'][0]['barcode']
            ?? $payload['barcode']
            ?? $payload['barkod']
            ?? data_get($payload, 'itemBarcodeList.0.barcode')
            ?? data_get($payload, 'itemBarcode.0.barcode')
            ?? '');

        $description = (string) ($payload['noteLarge']
            ?? $payload['noteLarge2']
            ?? $payload['description']
            ?? $payload['aciklama']
            ?? $payload['body_html']
            ?? $payload['detail']
            ?? $payload['note']
            ?? '');

        $stock = $variantStockSum > 0
            ? $variantStockSum
            : (int) ($payload['qty']
                ?? $payload['stock']
                ?? $payload['quantity']
                ?? $payload['stok']
                ?? $payload['available']
                ?? data_get($payload, 'bwhItemList.0.qtyPrm')
                ?? data_get($payload, 'bwhItemList.0.qtyAfterSalesPurchase')
                ?? data_get($payload, 'bwhItem.0.qty')
                ?? data_get($payload, 'bwhItems.0.qty')
                ?? 0);

        return [
            'uyumsoft_id' => $id !== '' ? $id : $sku,
            'sku' => $sku !== '' ? $sku : $id,
            'barcode' => $primaryBarcode,
            'title' => $title,
            'description' => $description,
            'variant_info' => $variantInfo,
            'images' => $this->extractImages($payload),
            'original_price' => $price,
            'stock' => $stock,
        ];
    }

    /**
     * Build variant rows from UyumCloud barcode + attribute lists.
     *
     * @param  array<string, mixed>  $payload
     * @return array{attributes: array<int, array<string, mixed>>, variants: array<int, array<string, mixed>>}
     */
    private function buildVariantInfo(array $payload, string $sku, float $defaultPrice): array
    {
        $attributeList = $payload['itemAttributeList'] ?? $payload['itemAttribute'] ?? [];
        $barcodeList = $payload['itemBarcodeList'] ?? $payload['itemBarcode'] ?? [];
        $priceList = $payload['itemPriceList'] ?? $payload['priceList'] ?? [];

        if (! is_array($attributeList)) {
            $attributeList = [];
        }
        if (! is_array($barcodeList)) {
            $barcodeList = [];
        }
        if (! is_array($priceList)) {
            $priceList = [];
        }

        $attrMap = [];
        $attributesByName = [];
        foreach ($attributeList as $attr) {
            if (! is_array($attr)) {
                continue;
            }
            $attrId = (string) ($attr['itemAttributeId'] ?? '');
            $attrName = (string) ($attr['itemAttributeName'] ?? $attr['attributeGrp'] ?? 'Özellik');
            $attrValue = (string) ($attr['itemAttributeValue']
                ?? $attr['itemAttributeDesc']
                ?? $attr['itemAttributeCode']
                ?? '');
            if ($attrId === '' || $attrValue === '') {
                continue;
            }
            $attrMap[$attrId] = [
                'name' => $attrName,
                'value' => $attrValue,
                'code' => (string) ($attr['itemAttributeCode'] ?? ''),
            ];
            $attributesByName[$attrName] ??= [];
            if (! in_array($attrValue, $attributesByName[$attrName], true)) {
                $attributesByName[$attrName][] = $attrValue;
            }
        }

        $priceByAttrKey = [];
        foreach ($priceList as $priceRow) {
            if (! is_array($priceRow)) {
                continue;
            }
            $key = ((string) ($priceRow['itemAttribute1Id'] ?? '0')).'|'.((string) ($priceRow['itemAttribute2Id'] ?? '0')).'|'.((string) ($priceRow['itemAttribute3Id'] ?? '0'));
            $priceByAttrKey[$key] = (float) ($priceRow['unitPriceTra'] ?? $priceRow['unitPrice'] ?? $defaultPrice);
        }

        $stockByAttrKey = $this->buildStockMapByAttributes($payload);
        $stockByBarcode = $this->buildStockMapByBarcode($payload);

        $variants = [];
        foreach ($barcodeList as $row) {
            if (! is_array($row)) {
                continue;
            }

            $attr1Id = (string) ($row['itemAttribute1Id'] ?? '0');
            $attr2Id = (string) ($row['itemAttribute2Id'] ?? '0');
            $attr3Id = (string) ($row['itemAttribute3Id'] ?? '0');
            $code1 = (string) ($row['itemAttributeCode1'] ?? '');
            $code2 = (string) ($row['itemAttributeCode2'] ?? '');
            $code3 = (string) ($row['itemAttributeCode3'] ?? '');

            $parts = [];
            foreach ([$attr1Id, $attr2Id, $attr3Id] as $index => $aid) {
                if ($aid !== '' && $aid !== '0' && isset($attrMap[$aid])) {
                    $parts[] = $attrMap[$aid]['value'];
                    continue;
                }

                $fallbackCode = [$code1, $code2, $code3][$index] ?? '';
                if ($fallbackCode !== '') {
                    $parts[] = $fallbackCode;
                }
            }

            $title = $parts !== [] ? implode(' / ', $parts) : 'Varsayılan';
            $barcode = (string) ($row['barcode'] ?? $row['barCode'] ?? $row['Barkod'] ?? '');
            $priceKey = $attr1Id.'|'.$attr2Id.'|'.$attr3Id;
            $variantSkuParts = array_filter(
                [$sku, $code1 !== '' ? $code1 : null, $code2 !== '' ? $code2 : null, $code3 !== '' ? $code3 : null],
                static fn ($v) => filled($v)
            );

            $attr1Value = ($attr1Id !== '0' && isset($attrMap[$attr1Id])) ? $attrMap[$attr1Id]['value'] : ($code1 !== '' ? $code1 : null);
            $attr2Value = ($attr2Id !== '0' && isset($attrMap[$attr2Id])) ? $attrMap[$attr2Id]['value'] : ($code2 !== '' ? $code2 : null);
            $attr3Value = ($attr3Id !== '0' && isset($attrMap[$attr3Id])) ? $attrMap[$attr3Id]['value'] : ($code3 !== '' ? $code3 : null);

            // Tek satırlık "boş" barkod kaydını (özelliksiz ve barkodsuz) atla.
            if ($barcode === '' && $attr1Value === null && $attr2Value === null && $attr3Value === null) {
                continue;
            }

            $variantStock = $stockByAttrKey[$priceKey]
                ?? ($barcode !== '' ? ($stockByBarcode[$barcode] ?? null) : null);

            $variants[] = [
                'title' => $title,
                'sku' => implode('-', $variantSkuParts) ?: $sku,
                'barcode' => $barcode !== '' ? $barcode : null,
                'price' => $priceByAttrKey[$priceKey] ?? $defaultPrice,
                'stock' => $variantStock,
                'attribute_1' => $attr1Value,
                'attribute_2' => $attr2Value,
                'attribute_3' => $attr3Value,
                'attribute_1_id' => $attr1Id !== '0' ? $attr1Id : null,
                'attribute_2_id' => $attr2Id !== '0' ? $attr2Id : null,
                'attribute_3_id' => $attr3Id !== '0' ? $attr3Id : null,
            ];
        }

        if ($variants === [] && isset($payload['variants']) && is_array($payload['variants'])) {
            foreach ($payload['variants'] as $variant) {
                if (! is_array($variant)) {
                    continue;
                }
                $variants[] = [
                    'title' => (string) ($variant['title'] ?? $variant['name'] ?? 'Varyant'),
                    'sku' => $variant['sku'] ?? $sku,
                    'barcode' => $variant['barcode'] ?? null,
                    'price' => (float) ($variant['price'] ?? $defaultPrice),
                    'stock' => $variant['stock'] ?? $variant['quantity'] ?? null,
                ];
            }
        }

        // Özellik listesinden de grup çıkar (barkod satırında eşleşme olmasa bile RENK/BEDEN görünsün).
        if ($attributesByName === [] && $variants !== []) {
            foreach (['attribute_1' => 'Özellik 1', 'attribute_2' => 'Özellik 2', 'attribute_3' => 'Özellik 3'] as $key => $label) {
                $values = [];
                foreach ($variants as $variant) {
                    $value = (string) ($variant[$key] ?? '');
                    if ($value !== '' && ! in_array($value, $values, true)) {
                        $values[] = $value;
                    }
                }
                if ($values !== []) {
                    $attributesByName[$label] = $values;
                }
            }
        }

        $attributes = [];
        foreach ($attributesByName as $name => $values) {
            $attributes[] = [
                'name' => $name,
                'values' => array_values($values),
            ];
        }

        if ($variants === []) {
            $fallbackBarcode = (string) ($payload['barcode'] ?? $payload['barkod'] ?? '');
            $fallbackStock = $stockByAttrKey['0|0|0'] ?? array_sum($stockByAttrKey);
            $variants[] = [
                'title' => 'Varsayılan',
                'sku' => $sku,
                'barcode' => $fallbackBarcode !== '' ? $fallbackBarcode : null,
                'price' => $defaultPrice,
                'stock' => $fallbackStock > 0 ? (int) $fallbackStock : null,
            ];
        }

        // Varyant stokları yoksa ama ürün seviyesi stok varsa tek varyanta yaz.
        $hasAnyVariantStock = collect($variants)->contains(static fn (array $v): bool => $v['stock'] !== null);
        if (! $hasAnyVariantStock && count($variants) === 1) {
            $productLevelStock = (int) ($payload['qty']
                ?? $payload['stock']
                ?? $payload['quantity']
                ?? data_get($payload, 'bwhItemList.0.qtyPrm')
                ?? data_get($payload, 'bwhItemList.0.qtyAfterSalesPurchase')
                ?? 0);
            if ($productLevelStock > 0) {
                $variants[0]['stock'] = $productLevelStock;
            }
        }

        return [
            'attributes' => $attributes,
            'variants' => $variants,
            'stock_total' => (int) collect($variants)->sum(static fn (array $v): int => (int) ($v['stock'] ?? 0)),
        ];
    }

    /**
     * Map warehouse qty rows to attribute combination keys.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, int>
     */
    private function buildStockMapByAttributes(array $payload): array
    {
        $rows = $payload['bwhItemDetailList']
            ?? $payload['bwhItemList']
            ?? $payload['bwhItem']
            ?? $payload['bwhItems']
            ?? $payload['itemBwhList']
            ?? [];

        if (! is_array($rows)) {
            return [];
        }

        $map = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $attr1 = (string) ($row['itemAttribute1Id'] ?? $row['attribute1Id'] ?? '0');
            $attr2 = (string) ($row['itemAttribute2Id'] ?? $row['attribute2Id'] ?? '0');
            $attr3 = (string) ($row['itemAttribute3Id'] ?? $row['attribute3Id'] ?? '0');

            // Ürün toplam satırı (özelliksiz) varyant eşlemesine yazılmaz.
            if (($attr1 === '' || $attr1 === '0')
                && ($attr2 === '' || $attr2 === '0')
                && ($attr3 === '' || $attr3 === '0')
                && empty($row['itemAttributeCode1'])
                && empty($row['itemAttributeCode2'])) {
                continue;
            }

            $key = $attr1.'|'.$attr2.'|'.$attr3;

            $qty = (int) ($row['qtyPrm']
                ?? $row['qtyAfterSalesPurchase']
                ?? $row['qty']
                ?? $row['quantity']
                ?? $row['stock']
                ?? $row['qtyFree']
                ?? $row['qtyFreePrm']
                ?? 0);

            $map[$key] = ($map[$key] ?? 0) + $qty;
        }

        return $map;
    }

    /**
     * Map warehouse qty by barcode when attribute ids are missing.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, int>
     */
    private function buildStockMapByBarcode(array $payload): array
    {
        $rows = $payload['bwhItemDetailList']
            ?? $payload['bwhItemList']
            ?? $payload['bwhItem']
            ?? $payload['bwhItems']
            ?? [];

        if (! is_array($rows)) {
            return [];
        }

        $map = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $barcode = (string) ($row['barcode'] ?? $row['barCode'] ?? $row['Barkod'] ?? '');
            if ($barcode === '') {
                continue;
            }
            $qty = (int) ($row['qtyPrm']
                ?? $row['qtyAfterSalesPurchase']
                ?? $row['qty']
                ?? $row['quantity']
                ?? $row['stock']
                ?? $row['qtyFreePrm']
                ?? 0);
            $map[$barcode] = ($map[$barcode] ?? 0) + $qty;
        }

        return $map;
    }

    /**
     * Resolve warehouse + item ids then fetch per-variant stock rows (UyumCloud).
     *
     * @param  array<string, mixed>  $payload
     * @return array<int, array<string, mixed>>
     */
    private function fetchVariantWarehouseStocks(array $payload, string $itemCode): array
    {
        $itemId = (int) ($payload['id']
            ?? $payload['itemId']
            ?? data_get($payload, 'bwhItemList.0.itemId')
            ?? 0);
        $branchId = (int) ($payload['branchId']
            ?? data_get($payload, 'bwhItemList.0.branchId')
            ?? 0);
        $whouseId = (int) ($payload['whouseId']
            ?? data_get($payload, 'bwhItemList.0.whouseId')
            ?? 0);

        if ($itemId <= 0 || $branchId <= 0 || $whouseId <= 0) {
            return [];
        }

        return $this->getItemWarehouseStocks($itemCode, $itemId, $branchId, $whouseId);
    }

    /**
     * Fetch per-attribute warehouse stock rows for an item (UyumCloud GetBwhItemDetail).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getItemWarehouseStocks(
        string $itemCode,
        ?int $itemId = null,
        ?int $branchId = null,
        ?int $whouseId = null
    ): array {
        if (! $this->isCloudApi) {
            return [];
        }

        if ($itemId === null || $branchId === null || $whouseId === null || $itemId <= 0 || $branchId <= 0 || $whouseId <= 0) {
            return [];
        }

        try {
            $response = $this->cloudRequest('INV/GetBwhItemDetail', [
                'value' => [
                    'itemId' => $itemId,
                    'branchId' => $branchId,
                    'whouseId' => $whouseId,
                ],
                'pageIndex' => 0,
                'pageSize' => 250,
            ]);

            return $this->extractCloudItems($response);
        } catch (UyumSoftException $e) {
            Log::channel('stack')->warning('GetBwhItemDetail failed, falling back to product payload stocks', [
                'item_code' => $itemCode,
                'item_id' => $itemId,
                'message' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @param  array<string, mixed>  $filter
     * @return array{items: array<int, array<string, mixed>>, total: int}
     */
    private function getCloudProducts(int $limit, int $offset, array $filter = []): array
    {
        if ($this->branchCode === '') {
            throw new UyumSoftException('UyumCloud için İşyeri Kodu (branch code) ayarlarda tanımlı olmalı.');
        }

        $pageSize = min(max($limit, 1), 250);
        $pageIndex = (int) floor($offset / $pageSize);

        $value = array_filter([
            'branchCode' => $this->branchCode,
            'whouseCode' => $this->warehouseCode !== '' ? $this->warehouseCode : null,
            'itemCode' => $filter['itemCode'] ?? $filter['sku'] ?? null,
            // Barkod + özellik (renk/beden) + fiyat + stok + görsel gelsin.
            'dontWants' => 'ItemEditor,ItemLang',
        ], static fn ($v) => $v !== null && $v !== '');

        $response = $this->cloudRequest('INV/GetItemList', [
            'value' => $value,
            'pageIndex' => $pageIndex,
            'pageSize' => $pageSize,
        ]);

        $items = $this->extractCloudItems($response);

        return [
            'items' => $items,
            'total' => (int) ($response['totalCount'] ?? count($items)),
        ];
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<int, array<string, mixed>>
     */
    private function extractCloudItems(array $response): array
    {
        $result = $response['result'] ?? [];

        if (is_array($result) && array_is_list($result)) {
            return array_values(array_filter($result, 'is_array'));
        }

        if (! is_array($result) || $result === []) {
            return [];
        }

        $masters = [];
        $others = [];
        foreach ($result as $key => $value) {
            if (! is_array($value) || $value === [] || ! array_is_list($value) || ! is_array($value[0] ?? null)) {
                continue;
            }

            $list = array_values(array_filter($value, 'is_array'));
            $lk = strtolower((string) $key);
            if (str_ends_with($lk, '_m') || in_array($lk, ['items', 'itemlist', 'data', 'result'], true)) {
                $masters = $list;
                break;
            }

            if ($others === []) {
                $others = $list;
            }
        }

        return $masters !== [] ? $masters : $others;
    }

    /**
     * Try multiple Cloud endpoints until one exists.
     *
     * @param  array<int, string>  $endpoints
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function cloudRequestFirst(array $endpoints, array $body, bool $allowEmpty = false): array
    {
        $last = null;

        foreach ($endpoints as $endpoint) {
            try {
                return $this->cloudRequest($endpoint, $body);
            } catch (UyumSoftException $e) {
                $last = $e;
                if (! $this->isMissingEndpoint($e)) {
                    throw $e;
                }
            }
        }

        if ($allowEmpty) {
            return ['result' => []];
        }

        throw $last ?? new UyumSoftException('UyumCloud sipariş/fatura endpoint’i bulunamadı.');
    }

    private function isMissingEndpoint(UyumSoftException $e): bool
    {
        $status = (int) ($e->getCode() ?: ($e->context()['status'] ?? 0));
        if (in_array($status, [404, 405, 501], true)) {
            return true;
        }

        $haystack = strtolower($e->getMessage().' '.json_encode($e->context(), JSON_UNESCAPED_UNICODE));

        return str_contains($haystack, 'not found')
            || str_contains($haystack, 'bulunamad')
            || str_contains($haystack, 'unknown endpoint')
            || str_contains($haystack, 'does not exist')
            || str_contains($haystack, 'geçersiz endpoint');
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<int, string>  $needles
     */
    private function recordMatchesNeedles(array $record, array $needles): bool
    {
        $needles = array_values(array_filter(array_map(
            static fn ($needle): string => strtolower(trim((string) $needle)),
            $needles
        )));

        if ($needles === []) {
            return false;
        }

        $fields = [
            $record['docNo'] ?? null,
            $record['orderNo'] ?? null,
            $record['invoiceNo'] ?? null,
            $record['sourceDocNo'] ?? null,
            $record['orderDocNo'] ?? null,
            $record['note1'] ?? null,
            $record['note2'] ?? null,
            $record['id'] ?? null,
            $record['orderId'] ?? null,
            $record['invoiceId'] ?? null,
        ];

        $haystack = strtolower(implode(' ', array_filter(array_map(
            static fn ($value): string => trim((string) $value),
            $fields
        ))));

        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function cloudRequest(string $endpoint, array $body): array
    {
        $this->authenticate();

        $url = $this->baseUrl.'/UyumApi/v1/'.ltrim($endpoint, '/');

        try {
            $response = $this->httpClient()
                ->withHeaders([
                    'Authorization' => 'Bearer '.$this->accessToken,
                    'UyumSecretKey' => (string) $this->uyumSecretKey,
                ])
                ->post($url, $body);
        } catch (ConnectionException $e) {
            throw new UyumSoftException('UyumSoft API ağ hatası: '.$e->getMessage(), [], 0, $e);
        }

        if ($response->failed()) {
            $this->handleErrors($response, $endpoint);
        }

        $payload = $response->json() ?? [];
        if (! is_array($payload)) {
            return [];
        }

        $statusCode = (int) ($payload['statusCode'] ?? $response->status());
        if ($statusCode >= 400) {
            $message = (string) ($payload['message'] ?? 'UyumCloud isteği başarısız.');
            $validation = data_get($payload, 'responseException.validationErrors');
            if (is_array($validation) && $validation !== []) {
                $details = collect($validation)->map(static fn (array $row): string => ($row['field'] ?? 'alan').': '.($row['message'] ?? ''))->implode(' ');
                $message .= ' '.$details;
            }

            throw new UyumSoftException("UyumSoft API hatası ({$statusCode}): {$message}", [
                'endpoint' => $endpoint,
                'body' => $payload,
            ], $statusCode);
        }

        Log::channel('stack')->info('UyumSoft cloud API success', [
            'endpoint' => $endpoint,
            'status' => $response->status(),
        ]);

        return $payload;
    }

    private function authenticate(bool $force = false): void
    {
        if (! $this->isConfigured()) {
            throw new UyumSoftException('UyumSoft API bilgileri yapılandırılmamış. Ayarlar veya .env dosyasını kontrol edin.');
        }

        if ($this->isCloudApi) {
            $this->authenticateCloud($force);

            return;
        }

        $this->authenticateLegacy($force);
    }

    private function authenticateCloud(bool $force = false): void
    {
        if (! $force && $this->accessToken && $this->uyumSecretKey) {
            return;
        }

        try {
            $response = $this->httpClient()
                ->post($this->baseUrl.'/UyumApi/v1/GNL/UyumLogin', [
                    'username' => $this->username,
                    'password' => $this->password,
                ]);
        } catch (ConnectionException $e) {
            throw new UyumSoftException('UyumSoft kimlik doğrulama ağ hatası: '.$e->getMessage(), [], 0, $e);
        }

        if ($response->failed()) {
            $this->handleErrors($response, 'GNL/UyumLogin');
        }

        $json = $response->json() ?? [];
        $result = is_array($json['result'] ?? null) ? $json['result'] : [];
        $token = (string) ($result['access_token'] ?? $result['accessToken'] ?? '');
        $secret = (string) ($result['uyumSecretKey'] ?? '');

        if ($token === '' || $secret === '') {
            throw new UyumSoftException('UyumSoft giriş yanıtında token alınamadı. WEBSERVIS kullanıcısının web servis yetkisi olduğundan emin olun.');
        }

        $this->accessToken = $token;
        $this->uyumSecretKey = $secret;
    }

    private function authenticateLegacy(bool $force = false): void
    {
        if (! $force && $this->accessToken) {
            return;
        }

        try {
            $response = Http::timeout(30)->acceptJson()->post($this->baseUrl.'/auth/login', [
                'username' => $this->username,
                'password' => $this->password,
                'userName' => $this->username,
            ]);

            if ($response->successful()) {
                $json = $response->json() ?? [];
                $token = $json['token']
                    ?? $json['access_token']
                    ?? $json['accessToken']
                    ?? data_get($json, 'data.token');

                if (is_string($token) && $token !== '') {
                    $this->accessToken = $token;

                    return;
                }
            }
        } catch (ConnectionException $e) {
            throw new UyumSoftException('UyumSoft kimlik doğrulama ağ hatası: '.$e->getMessage(), [], 0, $e);
        }

        $this->accessToken = null;
    }

    /**
     * @param  array<string, mixed>|null  $data
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function makeLegacyRequest(string $method, string $endpoint, ?array $data = null, array $query = []): array
    {
        $this->authenticate();

        $url = $this->baseUrl.'/'.ltrim($endpoint, '/');
        $attempts = 0;
        $maxAttempts = 3;

        while ($attempts < $maxAttempts) {
            $attempts++;

            try {
                $client = $this->httpClient()
                    ->withBasicAuth($this->username, $this->password);

                if ($this->accessToken) {
                    $client = $client->withToken($this->accessToken);
                }

                /** @var Response $response */
                $response = match (strtoupper($method)) {
                    'GET' => $client->get($url, $query),
                    'POST' => $client->post($url, $data ?? []),
                    'PUT' => $client->put($url, $data ?? []),
                    'DELETE' => $client->delete($url, $data ?? []),
                    default => throw new UyumSoftException("Desteklenmeyen HTTP metodu: {$method}"),
                };

                if (in_array($response->status(), [401, 403], true) && $attempts < $maxAttempts) {
                    $this->accessToken = null;
                    $this->uyumSecretKey = null;
                    $this->authenticate(true);
                    continue;
                }

                if ($response->status() === 429) {
                    sleep($attempts + 1);
                    continue;
                }

                if ($response->failed()) {
                    $this->handleErrors($response, $endpoint);
                }

                $payload = $response->json() ?? [];

                Log::channel('stack')->info('UyumSoft API success', [
                    'method' => $method,
                    'endpoint' => $endpoint,
                    'status' => $response->status(),
                ]);

                return is_array($payload) ? $payload : [];
            } catch (ConnectionException $e) {
                Log::channel('stack')->error('UyumSoft network error', [
                    'endpoint' => $endpoint,
                    'attempt' => $attempts,
                    'message' => $e->getMessage(),
                ]);

                if ($attempts >= $maxAttempts) {
                    throw new UyumSoftException('UyumSoft API ağ hatası: '.$e->getMessage(), [], 0, $e);
                }

                sleep($attempts);
            }
        }

        throw new UyumSoftException('UyumSoft API isteği başarısız oldu.');
    }

    private function httpClient(): PendingRequest
    {
        return Http::withoutVerifying()
            ->withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])
            ->timeout(45)
            ->acceptJson();
    }

    private function resolveCredential(string $settingKey, string $configKey, mixed $default = ''): string
    {
        $fromDb = Setting::getValue($settingKey);
        if (filled($fromDb)) {
            return trim((string) $fromDb);
        }

        $fromConfig = config($configKey);
        if (filled($fromConfig)) {
            return trim((string) $fromConfig);
        }

        return trim((string) $default);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, string>
     */
    private function extractImages(array $payload): array
    {
        $raw = $payload['images']
            ?? $payload['imageList']
            ?? $payload['Images']
            ?? $payload['itemImageList']
            ?? $payload['itemImage']
            ?? $payload['photos']
            ?? [];

        if (is_string($raw) && $raw !== '') {
            $raw = [$raw];
        }

        if (! is_array($raw)) {
            $single = $payload['image'] ?? $payload['imageUrl'] ?? $payload['picture'] ?? null;
            $raw = $single ? [$single] : [];
        }

        $urls = [];
        foreach ($raw as $item) {
            if (is_string($item) && filter_var($item, FILTER_VALIDATE_URL)) {
                $urls[] = $item;
            } elseif (is_array($item)) {
                $url = $item['src']
                    ?? $item['url']
                    ?? $item['image']
                    ?? $item['ImageUrl']
                    ?? $item['imagePath']
                    ?? $item['imageUrl']
                    ?? $item['fileUrl']
                    ?? null;
                if (is_string($url) && filter_var($url, FILTER_VALIDATE_URL)) {
                    $urls[] = $url;
                }
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  array<int, string>  $keys
     * @return array<int, array<string, mixed>>
     */
    private function extractList(array $response, array $keys): array
    {
        foreach ($keys as $key) {
            if (isset($response[$key]) && is_array($response[$key])) {
                return array_values($response[$key]);
            }
        }

        return array_is_list($response) ? $response : [];
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  array<int, string>  $keys
     * @return array<string, mixed>
     */
    private function extractItem(array $response, array $keys): array
    {
        foreach ($keys as $key) {
            if (isset($response[$key]) && is_array($response[$key])) {
                return $response[$key];
            }
        }

        return $response;
    }

    private function handleErrors(Response $response, string $endpoint): void
    {
        $raw = (string) $response->body();
        $json = $response->json();
        $message = $this->summarizeErrorMessage($response, $endpoint, is_array($json) ? $json : null, $raw);

        Log::channel('stack')->error('UyumSoft API error', [
            'endpoint' => $endpoint,
            'status' => $response->status(),
            'message' => $message,
        ]);

        throw new UyumSoftException(
            "UyumSoft API hatası ({$response->status()}): {$message}",
            [
                'endpoint' => $endpoint,
                'status' => $response->status(),
            ],
            $response->status()
        );
    }

    /**
     * @param  array<string, mixed>|null  $json
     */
    private function summarizeErrorMessage(Response $response, string $endpoint, ?array $json, string $raw): string
    {
        if (str_contains($raw, '<html') || str_contains($raw, 'File or directory not found')) {
            return "Endpoint bulunamadı ({$endpoint}).";
        }

        if (is_array($json)) {
            $parts = array_filter([
                (string) ($json['message'] ?? ''),
                (string) data_get($json, 'responseException.exceptionMessage', ''),
            ]);
            $errors = data_get($json, 'responseException.validationErrors', []);
            if (is_array($errors) && $errors !== []) {
                $parts[] = collect($errors)
                    ->map(static fn ($row): string => is_array($row) ? (string) ($row['message'] ?? '') : (string) $row)
                    ->filter()
                    ->implode(' ');
            }

            $summary = trim(implode(' ', array_unique($parts)));
            if ($summary !== '') {
                return $summary;
            }
        }

        $plain = trim(strip_tags($raw));

        return $plain !== '' ? \Illuminate\Support\Str::limit($plain, 280) : 'Bilinmeyen API hatası.';
    }
}
