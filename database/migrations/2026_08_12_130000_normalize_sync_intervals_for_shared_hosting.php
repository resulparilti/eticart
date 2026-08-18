<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Paylaşımlı hosting cron (min 15 dk) ile uyumlu aralıklara yükselt.
     */
    public function up(): void
    {
        $min = max(15, (int) config('eticart.schedule_cron_minutes', 15));

        foreach (['sync_orders_interval', 'sync_stock_interval', 'sync_cargo_interval', 'sync_uyumsoft_orders_interval'] as $key) {
            DB::table('settings')
                ->where('key', $key)
                ->whereRaw('CAST(value AS UNSIGNED) < ?', [$min])
                ->update(['value' => (string) $min]);
        }

        DB::table('settings')
            ->where('key', 'sync_products_interval')
            ->whereRaw('CAST(value AS UNSIGNED) < ?', [$min])
            ->update(['value' => (string) max($min, 30)]);

        DB::table('sync_jobs')
            ->whereIn('job_type', ['order_sync', 'stock_sync', 'cargo_tracking', 'uyumsoft_order_sync'])
            ->where('interval_minutes', '<', $min)
            ->update(['interval_minutes' => $min]);

        DB::table('sync_jobs')
            ->where('job_type', 'product_sync')
            ->where('interval_minutes', '<', $min)
            ->update(['interval_minutes' => max($min, 30)]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Geri alma gerekmez.
    }
};
