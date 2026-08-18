<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('shopify_orders') || ! Schema::hasColumn('shopify_orders', 'shipping_address')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE shopify_orders MODIFY shipping_address TEXT NULL');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('shopify_orders') || ! Schema::hasColumn('shopify_orders', 'shipping_address')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE shopify_orders MODIFY shipping_address VARCHAR(255) NULL');
        }
    }
};
