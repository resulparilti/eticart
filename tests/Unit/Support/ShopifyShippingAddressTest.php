<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\ShopifyShippingAddress;
use Tests\TestCase;

class ShopifyShippingAddressTest extends TestCase
{
    public function test_zip_is_not_used_as_city_when_province_missing(): void
    {
        $locality = ShopifyShippingAddress::fromShopify([
            'address1' => 'Çekirge Cad. No 12',
            'city' => 'Bursa',
            'zip' => '16330',
            'country' => 'Turkey',
        ]);

        $this->assertSame('BURSA', $locality['city']);
        $this->assertSame('BURSA', $locality['town']);
        $this->assertSame('16330', $locality['zip']);
        $this->assertStringNotContainsString('16330', $locality['street']);
        $this->assertStringContainsString('Çekirge', $locality['street']);
    }

    public function test_province_is_il_and_city_is_district(): void
    {
        $locality = ShopifyShippingAddress::fromShopify([
            'address1' => 'Çekirge Mah. Hamam Sok. No 5',
            'city' => 'Osmangazi',
            'province' => 'Bursa',
            'zip' => '16330',
            'country' => 'Turkey',
        ]);

        $this->assertSame('BURSA', $locality['city']);
        $this->assertSame('OSMANGAZI', $locality['town']);
        $this->assertSame('16330', $locality['zip']);
    }

    public function test_legacy_concatenated_address_does_not_send_zip_as_il(): void
    {
        $locality = ShopifyShippingAddress::fromOrderFields(
            'Çekirge Cad. No 12, 16330, Turkey',
            'Bursa'
        );

        $this->assertSame('BURSA', $locality['city']);
        $this->assertNotSame('16330', $locality['city']);
        $this->assertNotSame('16330', $locality['town']);
    }

    public function test_province_code_16_maps_to_bursa(): void
    {
        $locality = ShopifyShippingAddress::fromShopify([
            'address1' => 'Nilüfer Mah. 1. Sokak No 8',
            'city' => 'Nilüfer',
            'province_code' => '16',
            'zip' => '16110',
        ]);

        $this->assertSame('BURSA', $locality['city']);
        $this->assertSame('NİLÜFER', $locality['town']);
    }
}
