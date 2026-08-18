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
}
