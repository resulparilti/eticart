<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    /**
     * Seed default application settings.
     */
    public function run(): void
    {
        $settings = [
            ['key' => 'shopify_store_url', 'value' => '', 'category' => 'shopify', 'label' => 'Shopify Store URL'],
            ['key' => 'shopify_access_token', 'value' => '', 'category' => 'shopify', 'label' => 'Shopify Access Token'],
            ['key' => 'shopify_api_version', 'value' => '2024-01', 'category' => 'shopify', 'label' => 'Shopify API Version'],

            ['key' => 'uyumsoft_api_user', 'value' => '', 'category' => 'uyumsoft', 'label' => 'UyumSoft API User'],
            ['key' => 'uyumsoft_api_password', 'value' => '', 'category' => 'uyumsoft', 'label' => 'UyumSoft API Password'],
            ['key' => 'uyumsoft_warehouse_id', 'value' => '', 'category' => 'uyumsoft', 'label' => 'UyumSoft Depo Kodu'],
            ['key' => 'uyumsoft_branch_code', 'value' => '', 'category' => 'uyumsoft', 'label' => 'UyumSoft İşyeri Kodu'],
            ['key' => 'uyumsoft_base_url', 'value' => '', 'category' => 'uyumsoft', 'label' => 'UyumSoft Base URL'],
            ['key' => 'uyumsoft_ecommerce_entity_code', 'value' => '', 'category' => 'uyumsoft', 'label' => 'UyumSoft E-ticaret Cari Kodu'],

            ['key' => 'mail_from_address', 'value' => 'noreply@eticart.local', 'category' => 'mail', 'label' => 'Mail From Address'],
            ['key' => 'mail_from_name', 'value' => 'EtiCart', 'category' => 'mail', 'label' => 'Mail From Name'],

            ['key' => 'sms_provider', 'value' => 'netgsm', 'category' => 'sms', 'label' => 'SMS Provider'],
            ['key' => 'sms_api_key', 'value' => '', 'category' => 'sms', 'label' => 'SMS API Key'],
            ['key' => 'sms_api_secret', 'value' => '', 'category' => 'sms', 'label' => 'SMS API Secret'],
            ['key' => 'sms_header', 'value' => 'ETICART', 'category' => 'sms', 'label' => 'SMS Header'],

            ['key' => 'sync_orders_interval', 'value' => '15', 'category' => 'sync', 'label' => 'Sipariş Sync (dk)'],
            ['key' => 'sync_products_interval', 'value' => '30', 'category' => 'sync', 'label' => 'Ürün Sync (dk)'],
            ['key' => 'sync_stock_interval', 'value' => '15', 'category' => 'sync', 'label' => 'Stok Sync (dk)'],
            ['key' => 'sync_cargo_interval', 'value' => '15', 'category' => 'sync', 'label' => 'Kargo Sync (dk)'],
            ['key' => 'sync_uyumsoft_orders_interval', 'value' => '15', 'category' => 'sync', 'label' => 'UyumSoft Sipariş Sync (dk)'],
            ['key' => 'auto_create_shipment', 'value' => '0', 'category' => 'sync', 'label' => 'Otomatik Kargo Oluştur'],
            ['key' => 'auto_send_tracking', 'value' => '0', 'category' => 'sync', 'label' => 'Otomatik Tracking Gönder'],
        ];

        foreach ($settings as $setting) {
            Setting::query()->firstOrCreate(
                ['key' => $setting['key']],
                $setting
            );
        }
    }
}
