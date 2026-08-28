<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Exceptions\UyumSoftException;
use App\Services\UyumSoftService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UyumSoftServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.uyumsoft.username' => 'demo',
            'services.uyumsoft.password' => 'secret',
            'services.uyumsoft.base_url' => 'https://api.uyumsoft.test/api/v1',
            'services.uyumsoft.warehouse_id' => '1',
        ]);
    }

    public function test_is_configured(): void
    {
        $this->assertTrue((new UyumSoftService())->isConfigured());
    }

    public function test_get_products_returns_items(): void
    {
        Http::fake([
            'api.uyumsoft.test/*' => Http::sequence()
                ->push(['token' => 'abc'], 200)
                ->push([
                    'items' => [
                        ['id' => 'P1', 'sku' => 'SKU-1', 'name' => 'Ürün 1', 'price' => 100, 'stock' => 5],
                    ],
                    'total' => 1,
                ], 200),
        ]);

        $result = (new UyumSoftService())->getProducts(10);

        $this->assertSame(1, $result['total']);
        $this->assertNotEmpty($result['items']);
    }

    public function test_cloud_login_and_products(): void
    {
        config([
            'services.uyumsoft.username' => 'WEBSERVIS',
            'services.uyumsoft.password' => 'secret',
            'services.uyumsoft.base_url' => 'https://tenant.eko.uyumcloud.com',
            'services.uyumsoft.branch_code' => '001',
            'services.uyumsoft.warehouse_id' => 'A001',
        ]);

        Http::fake([
            'tenant.eko.uyumcloud.com/UyumApi/v1/GNL/UyumLogin' => Http::response([
                'statusCode' => 200,
                'result' => [
                    'access_token' => 'token-abc',
                    'uyumSecretKey' => 'secret-key',
                ],
            ], 200),
            'tenant.eko.uyumcloud.com/UyumApi/v1/INV/GetItemList' => Http::response([
                'statusCode' => 200,
                'totalCount' => 1,
                'result' => [
                    ['itemCode' => 'STK-1', 'itemName' => 'Cloud Ürün', 'priceList' => [['unitPrice' => 120]]],
                ],
            ], 200),
        ]);

        $service = new UyumSoftService();
        $this->assertTrue($service->usesCloudApi());

        $connection = $service->testConnection();
        $this->assertTrue($connection['ok']);

        $products = $service->getProducts(10);
        $this->assertCount(1, $products['items']);
    }

    public function test_throws_when_not_configured(): void
    {
        config([
            'services.uyumsoft.username' => '',
            'services.uyumsoft.password' => '',
        ]);

        $this->expectException(UyumSoftException::class);

        (new UyumSoftService())->getProducts();
    }

    public function test_normalize_cloud_product_with_barcodes_and_attributes(): void
    {
        $service = new UyumSoftService();

        $normalized = $service->normalizeProduct([
            'itemCode' => 'BLZ-001',
            'itemName' => 'Bluz',
            'itemPriceList' => [
                [
                    'itemAttribute1Id' => 10,
                    'itemAttribute2Id' => 20,
                    'itemAttribute3Id' => 0,
                    'unitPriceTra' => 499.90,
                ],
            ],
            'itemAttributeList' => [
                ['itemAttributeId' => 10, 'itemAttributeName' => 'RENK', 'itemAttributeValue' => 'Siyah', 'itemAttributeCode' => 'SIYAH'],
                ['itemAttributeId' => 20, 'itemAttributeName' => 'BEDEN', 'itemAttributeValue' => 'M', 'itemAttributeCode' => 'M'],
                ['itemAttributeId' => 21, 'itemAttributeName' => 'BEDEN', 'itemAttributeValue' => 'L', 'itemAttributeCode' => 'L'],
            ],
            'itemBarcodeList' => [
                [
                    'barcode' => '8690000000011',
                    'itemAttribute1Id' => 10,
                    'itemAttribute2Id' => 20,
                    'itemAttribute3Id' => 0,
                    'itemAttributeCode1' => 'SIYAH',
                    'itemAttributeCode2' => 'M',
                    'itemAttributeCode3' => null,
                ],
                [
                    'barcode' => '8690000000012',
                    'itemAttribute1Id' => 10,
                    'itemAttribute2Id' => 21,
                    'itemAttribute3Id' => 0,
                    'itemAttributeCode1' => 'SIYAH',
                    'itemAttributeCode2' => 'L',
                    'itemAttributeCode3' => null,
                ],
            ],
            'bwhItemList' => [
                ['itemAttribute1Id' => 10, 'itemAttribute2Id' => 20, 'itemAttribute3Id' => 0, 'qtyPrm' => 5],
                ['itemAttribute1Id' => 10, 'itemAttribute2Id' => 21, 'itemAttribute3Id' => 0, 'qtyPrm' => 7],
            ],
        ]);

        $this->assertSame('BLZ-001', $normalized['uyumsoft_id']);
        $this->assertSame('8690000000011', $normalized['barcode']);
        $this->assertSame(499.9, $normalized['original_price']);
        $this->assertSame(12, $normalized['stock']);
        $this->assertCount(2, $normalized['variant_info']['variants']);
        $this->assertSame('Siyah / M', $normalized['variant_info']['variants'][0]['title']);
        $this->assertSame(5, $normalized['variant_info']['variants'][0]['stock']);
        $this->assertSame(7, $normalized['variant_info']['variants'][1]['stock']);
        $this->assertSame('8690000000012', $normalized['variant_info']['variants'][1]['barcode']);
        $this->assertSame(
            [
                ['name' => 'RENK', 'values' => ['Siyah']],
                ['name' => 'BEDEN', 'values' => ['M', 'L']],
            ],
            $normalized['variant_info']['attributes']
        );
    }

    public function test_normalize_uses_bwh_item_detail_variant_stocks(): void
    {
        $service = new UyumSoftService();

        $normalized = $service->normalizeProduct([
            'itemCode' => '10BRU001',
            'itemName' => 'Bere',
            'qty' => 85,
            'itemAttributeList' => [
                ['itemAttributeId' => 270, 'itemAttributeName' => 'BEDEN', 'itemAttributeValue' => 'STD', 'itemAttributeCode' => 'STD'],
                ['itemAttributeId' => 271, 'itemAttributeName' => 'RENK', 'itemAttributeValue' => '300700', 'itemAttributeCode' => '300700'],
                ['itemAttributeId' => 272, 'itemAttributeName' => 'RENK', 'itemAttributeValue' => '300730', 'itemAttributeCode' => '300730'],
            ],
            'itemBarcodeList' => [
                [
                    'barcode' => '8685130000001',
                    'itemAttribute1Id' => 270,
                    'itemAttribute2Id' => 271,
                    'itemAttribute3Id' => 0,
                ],
                [
                    'barcode' => '8685130000018',
                    'itemAttribute1Id' => 270,
                    'itemAttribute2Id' => 272,
                    'itemAttribute3Id' => 0,
                ],
            ],
            // Ürün toplamı — özellik yok
            'bwhItemList' => [
                ['itemId' => 1, 'branchId' => 1, 'whouseId' => 1, 'qtyPrm' => 85],
            ],
            // Varyant kırılımı (GetBwhItemDetail)
            'bwhItemDetailList' => [
                ['itemAttribute1Id' => 270, 'itemAttribute2Id' => 271, 'itemAttribute3Id' => 0, 'qtyPrm' => 1],
                ['itemAttribute1Id' => 270, 'itemAttribute2Id' => 272, 'itemAttribute3Id' => 0, 'qtyPrm' => 11],
            ],
        ]);

        $this->assertSame(12, $normalized['stock']);
        $this->assertSame(1, $normalized['variant_info']['variants'][0]['stock']);
        $this->assertSame(11, $normalized['variant_info']['variants'][1]['stock']);
    }

    public function test_normalize_applies_variant_price_list_discount(): void
    {
        $normalized = (new UyumSoftService())->normalizeProduct([
            'itemCode' => '10BRU001',
            'itemName' => 'Bere',
            'itemAttributeList' => [
                ['itemAttributeId' => 270, 'itemAttributeName' => 'BEDEN', 'itemAttributeValue' => 'STD', 'itemAttributeCode' => 'STD'],
                ['itemAttributeId' => 271, 'itemAttributeName' => 'RENK', 'itemAttributeValue' => 'Siyah', 'itemAttributeCode' => 'SIYAH'],
                ['itemAttributeId' => 272, 'itemAttributeName' => 'RENK', 'itemAttributeValue' => 'Kırmızı', 'itemAttributeCode' => 'KIRMIZI'],
            ],
            'itemBarcodeList' => [
                [
                    'barcode' => '8685130000001',
                    'itemAttribute1Id' => 270,
                    'itemAttribute2Id' => 271,
                    'itemAttribute3Id' => 0,
                    'itemAttributeCode1' => 'STD',
                    'itemAttributeCode2' => 'SIYAH',
                ],
                [
                    'barcode' => '8685130000018',
                    'itemAttribute1Id' => 270,
                    'itemAttribute2Id' => 272,
                    'itemAttribute3Id' => 0,
                    'itemAttributeCode1' => 'STD',
                    'itemAttributeCode2' => 'KIRMIZI',
                ],
            ],
            'itemPriceList' => [
                [
                    'itemAttribute1Id' => 270,
                    'itemAttribute2Id' => 271,
                    'itemAttribute3Id' => 0,
                    'unitPriceTra' => 1000,
                    'disc1Rate' => 20,
                    'disc2Rate' => 0,
                    'disc3Rate' => 0,
                ],
                [
                    'itemAttribute1Id' => 270,
                    'itemAttribute2Id' => 272,
                    'itemAttribute3Id' => 0,
                    'unitPriceTra' => 1000,
                    'disc1Rate' => 0,
                    'disc2Rate' => 0,
                    'disc3Rate' => 0,
                ],
            ],
        ]);

        $discounted = $normalized['variant_info']['variants'][0];
        $fullPrice = $normalized['variant_info']['variants'][1];

        $this->assertSame(800.0, $discounted['price']);
        $this->assertSame(1000.0, $discounted['compare_at_price']);
        $this->assertSame(20.0, $discounted['disc1_rate']);
        $this->assertSame(1000.0, $fullPrice['price']);
        $this->assertNull($fullPrice['compare_at_price']);
    }

    public function test_normalize_applies_variant_amount_discount_from_disc_code(): void
    {
        $normalized = (new UyumSoftService())->normalizeProduct([
            'itemCode' => '20ATU008',
            'itemName' => 'Runner',
            'itemAttributeList' => [
                ['itemAttributeId' => 349, 'itemAttributeName' => 'BEDEN', 'itemAttributeValue' => '30x180 cm', 'itemAttributeCode' => '30x180'],
                ['itemAttributeId' => 359, 'itemAttributeName' => 'RENK', 'itemAttributeValue' => 'Kırmızı', 'itemAttributeCode' => '300755'],
                ['itemAttributeId' => 360, 'itemAttributeName' => 'RENK', 'itemAttributeValue' => 'Bebek Mavisi', 'itemAttributeCode' => '300687'],
            ],
            'itemBarcodeList' => [
                [
                    'barcode' => '8685130000810',
                    'itemAttribute1Id' => 349,
                    'itemAttribute2Id' => 359,
                    'itemAttribute3Id' => 0,
                    'itemAttributeCode1' => '30x180',
                    'itemAttributeCode2' => '300755',
                ],
                [
                    'barcode' => '8685130000834',
                    'itemAttribute1Id' => 349,
                    'itemAttribute2Id' => 360,
                    'itemAttribute3Id' => 0,
                    'itemAttributeCode1' => '30x180',
                    'itemAttributeCode2' => '300687',
                ],
            ],
            'itemPriceList' => [
                [
                    'itemAttribute1Id' => 349,
                    'itemAttribute2Id' => 359,
                    'itemAttribute3Id' => 0,
                    'unitPriceTra' => 5830,
                    'disc1Rate' => 0,
                    'disc2Rate' => 1000,
                    'disc3Rate' => 0,
                    'discCode1' => '',
                    'discCode2' => 'TUTAR',
                    'discCode3' => '',
                ],
                [
                    'itemAttribute1Id' => 349,
                    'itemAttribute2Id' => 360,
                    'itemAttribute3Id' => 0,
                    'unitPriceTra' => 5830,
                    'disc1Rate' => 10,
                    'disc2Rate' => 0,
                    'disc3Rate' => 0,
                    'discCode1' => 'ORAN',
                    'discCode2' => '',
                    'discCode3' => '',
                ],
            ],
        ]);

        $byBarcode = [];
        foreach ($normalized['variant_info']['variants'] as $variant) {
            $byBarcode[$variant['barcode']] = $variant;
        }

        $amount = $byBarcode['8685130000810'];
        $percent = $byBarcode['8685130000834'];

        $this->assertSame(4830.0, $amount['price']);
        $this->assertSame(5830.0, $amount['compare_at_price']);
        $this->assertSame(1000.0, $amount['disc2_rate']);
        $this->assertSame(5247.0, $percent['price']);
        $this->assertSame(5830.0, $percent['compare_at_price']);
        $this->assertSame(10.0, $percent['disc1_rate']);
    }

    public function test_cloud_create_sales_order_and_find_invoice(): void
    {
        config([
            'services.uyumsoft.username' => 'WEBSERVIS',
            'services.uyumsoft.password' => 'secret',
            'services.uyumsoft.base_url' => 'https://tenant.eko.uyumcloud.com',
            'services.uyumsoft.branch_code' => '001',
            'services.uyumsoft.warehouse_id' => 'A001',
        ]);

        Http::fake([
            'tenant.eko.uyumcloud.com/UyumApi/v1/GNL/UyumLogin' => Http::response([
                'statusCode' => 200,
                'result' => [
                    'access_token' => 'token-abc',
                    'uyumSecretKey' => 'secret-key',
                ],
            ], 200),
            'tenant.eko.uyumcloud.com/UyumApi/v1/PSM/InsertOrderM' => Http::response([
                'statusCode' => 200,
                'result' => ['id' => 44, 'docNo' => 'SH1002'],
            ], 200),
            'tenant.eko.uyumcloud.com/UyumApi/v1/PSM/GetInvoice' => Http::response([
                'statusCode' => 200,
                'result' => [
                    'invoicE_M' => [
                        ['id' => 9, 'docNo' => 'SH1002', 'invoiceNo' => 'EA-1', 'gnlNote6' => 'Sipariş Numarası: 1002'],
                    ],
                ],
            ], 200),
            'tenant.eko.uyumcloud.com/UyumApi/v1/FIN/GetInvoiceMList' => Http::response([
                'statusCode' => 200,
                'result' => [
                    ['id' => 9, 'docNo' => 'SH1002', 'invoiceNo' => 'EA-1', 'gnlNote6' => 'Sipariş Numarası: 1002'],
                ],
            ], 200),
        ]);

        $service = new UyumSoftService();
        $created = $service->createSalesOrder(['docNo' => 'SH1002', 'entityCode' => 'ETICARET']);
        $this->assertSame(44, data_get($created, 'result.id'));

        $invoice = $service->findInvoiceForOrder('SH1002', '#1002');
        $this->assertNotNull($invoice);
        $this->assertSame('EA-1', $invoice['invoiceNo']);
    }

    public function test_invoice_document_falls_back_from_empty_pdf_to_xml(): void
    {
        config([
            'services.uyumsoft.username' => 'WEBSERVIS',
            'services.uyumsoft.password' => 'secret',
            'services.uyumsoft.base_url' => 'https://tenant.eko.uyumcloud.com',
        ]);

        $xml = '<?xml version="1.0" encoding="UTF-8"?><Invoice><ID>EA-1</ID></Invoice>';

        Http::fake(function ($request) use ($xml) {
            if (str_contains($request->url(), 'GNL/UyumLogin')) {
                return Http::response([
                    'statusCode' => 200,
                    'result' => [
                        'access_token' => 'token-abc',
                        'uyumSecretKey' => 'secret-key',
                    ],
                ]);
            }

            if (str_contains($request->url(), 'FIN/GetInvoiceXml')) {
                return Http::response([
                    'statusCode' => 200,
                    'result' => ['xml' => base64_encode($xml)],
                ]);
            }

            return Http::response([
                'statusCode' => 200,
                'result' => [],
            ]);
        });

        $document = (new UyumSoftService())->getInvoiceDocument(9);

        $this->assertNotNull($document);
        $this->assertSame('xml', $document['extension']);
        $this->assertSame('application/xml', $document['mime']);
        $this->assertSame('FIN/GetInvoiceXml', $document['source']);
        $this->assertSame($xml, $document['content']);
    }

    public function test_invoice_search_rejects_first_unrelated_result_when_filter_is_ignored(): void
    {
        config([
            'services.uyumsoft.username' => 'WEBSERVIS',
            'services.uyumsoft.password' => 'secret',
            'services.uyumsoft.base_url' => 'https://tenant.eko.uyumcloud.com',
        ]);

        Http::fake(function ($request) {
            if (str_contains($request->url(), 'GNL/UyumLogin')) {
                return Http::response([
                    'statusCode' => 200,
                    'result' => [
                        'access_token' => 'token-abc',
                        'uyumSecretKey' => 'secret-key',
                    ],
                ]);
            }

            return Http::response([
                'statusCode' => 200,
                'result' => [[
                    'id' => '84888',
                    'docNo' => '544051238',
                    'sourceMId' => '0',
                    'note1' => 'İLGİSİZ ALIŞ FATURASI',
                ]],
            ]);
        });

        $invoice = (new UyumSoftService())->findInvoiceForOrder('SH1003', '#1003', '7617');

        $this->assertNull($invoice);
    }

    public function test_invoice_search_matches_labeled_order_number_from_invoice_details(): void
    {
        config([
            'services.uyumsoft.username' => 'WEBSERVIS',
            'services.uyumsoft.password' => 'secret',
            'services.uyumsoft.base_url' => 'https://tenant.eko.uyumcloud.com',
        ]);

        Http::fake(function ($request) {
            if (str_contains($request->url(), 'GNL/UyumLogin')) {
                return Http::response([
                    'statusCode' => 200,
                    'result' => [
                        'access_token' => 'token-abc',
                        'uyumSecretKey' => 'secret-key',
                    ],
                ]);
            }

            if (str_contains($request->url(), 'FIN/GetInvoiceM') && ! str_contains($request->url(), 'List')) {
                return Http::response([
                    'statusCode' => 200,
                    'result' => [
                        'id' => '84917',
                        'docNo' => 'ORE2026000000001',
                        'sourceMId' => '35905',
                        'entityName' => 'ALI DINIZ',
                        'amtReceipt' => '2.475,00',
                        'curTraCode' => 'TRY',
                        'gnlNote6' => 'Sipariş Numarası: 1003',
                        'gnlNote5' => 'Toplam Tutar: 2475',
                    ],
                ]);
            }

            return Http::response([
                'statusCode' => 200,
                'result' => [
                    [
                        'id' => '84888',
                        'docNo' => '544051238',
                        'sourceMId' => '0',
                        'purchaseSales' => 'Alış',
                        'entityName' => 'ALI DINIZ',
                        'amtReceipt' => '2.475,00',
                        'curTraCode' => 'TRY',
                    ],
                    [
                        'id' => '84917',
                        'docNo' => 'ORE2026000000001',
                        'sourceMId' => '35905',
                        'entityName' => 'ALI DINIZ',
                        'amtReceipt' => '2.475,00',
                        'curTraCode' => 'TRY',
                    ],
                    [
                        'id' => '84999',
                        'docNo' => 'ORE2026000000099',
                        'sourceMId' => '0',
                        'entityName' => 'ALI DINIZ',
                        'amtReceipt' => '2.475,00',
                        'curTraCode' => 'TRY',
                        'gnlNote6' => 'Sipariş Numarası: 10030',
                    ],
                ],
            ]);
        });

        $invoice = (new UyumSoftService())->findInvoiceForOrder(
            'SH1003',
            '#1003',
            '7617',
            [
                'customer_name' => 'Ali Diniz',
                'total' => 2475,
                'currency' => 'TRY',
            ]
        );

        $this->assertNotNull($invoice);
        $this->assertSame('84917', $invoice['id']);
        $this->assertSame('Sipariş Numarası: 1003', $invoice['gnlNote6']);
    }

    public function test_invoice_search_does_not_match_customer_amount_without_order_number_note(): void
    {
        config([
            'services.uyumsoft.username' => 'WEBSERVIS',
            'services.uyumsoft.password' => 'secret',
            'services.uyumsoft.base_url' => 'https://tenant.eko.uyumcloud.com',
        ]);

        Http::fake(function ($request) {
            if (str_contains($request->url(), 'GNL/UyumLogin')) {
                return Http::response([
                    'statusCode' => 200,
                    'result' => [
                        'access_token' => 'token-abc',
                        'uyumSecretKey' => 'secret-key',
                    ],
                ]);
            }

            return Http::response([
                'statusCode' => 200,
                'result' => [[
                    'id' => '84917',
                    'docNo' => 'ORE2026000000001',
                    'sourceMId' => '35905',
                    'entityName' => 'ALI DINIZ',
                    'amtReceipt' => '2.475,00',
                    'curTraCode' => 'TRY',
                ]],
            ]);
        });

        $invoice = (new UyumSoftService())->findInvoiceForOrder(
            'SH1003',
            '#1003',
            '7617',
            [
                'customer_name' => 'Ali Diniz',
                'total' => 2475,
                'currency' => 'TRY',
            ]
        );

        $this->assertNull($invoice);
    }

    public function test_invoice_search_rejects_ambiguous_labeled_order_numbers(): void
    {
        config([
            'services.uyumsoft.username' => 'WEBSERVIS',
            'services.uyumsoft.password' => 'secret',
            'services.uyumsoft.base_url' => 'https://tenant.eko.uyumcloud.com',
        ]);

        Http::fake(function ($request) {
            if (str_contains($request->url(), 'GNL/UyumLogin')) {
                return Http::response([
                    'statusCode' => 200,
                    'result' => [
                        'access_token' => 'token-abc',
                        'uyumSecretKey' => 'secret-key',
                    ],
                ]);
            }

            return Http::response([
                'statusCode' => 200,
                'result' => [
                    [
                        'id' => '84917',
                        'docNo' => 'ORE2026000000001',
                        'gnlNote6' => 'Sipariş Numarası: 1003',
                    ],
                    [
                        'id' => '84918',
                        'docNo' => 'ORE2026000000002',
                        'gnlNote6' => 'Sipariş Numarası: 1003',
                    ],
                ],
            ]);
        });

        $invoice = (new UyumSoftService())->findInvoiceForOrder('SH1003', '#1003');

        $this->assertNull($invoice);
    }
}
