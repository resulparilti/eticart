<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopify_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('shopify_orders', 'shipping_province')) {
                $table->string('shipping_province', 100)->nullable()->after('shipping_city');
            }
            if (! Schema::hasColumn('shopify_orders', 'shipping_zip')) {
                $table->string('shipping_zip', 16)->nullable()->after('shipping_province');
            }
        });
    }

    public function down(): void
    {
        Schema::table('shopify_orders', function (Blueprint $table) {
            if (Schema::hasColumn('shopify_orders', 'shipping_zip')) {
                $table->dropColumn('shipping_zip');
            }
            if (Schema::hasColumn('shopify_orders', 'shipping_province')) {
                $table->dropColumn('shipping_province');
            }
        });
    }
};
