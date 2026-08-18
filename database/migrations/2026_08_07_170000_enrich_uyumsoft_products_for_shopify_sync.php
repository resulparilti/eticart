<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('uyumsoft_products', function (Blueprint $table) {
            $table->text('description')->nullable()->after('title');
            $table->string('barcode')->nullable()->index()->after('sku');
            $table->json('images')->nullable()->after('variant_info');
            $table->boolean('is_active')->default(true)->index()->after('synced_to_shopify');
            $table->timestamp('shopify_synced_at')->nullable()->after('last_sync');
        });

        Schema::table('shopify_products', function (Blueprint $table) {
            if (! Schema::hasColumn('shopify_products', 'inventory_item_id')) {
                $table->string('inventory_item_id')->nullable()->after('shopify_variant_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('uyumsoft_products', function (Blueprint $table) {
            $table->dropColumn([
                'description',
                'barcode',
                'images',
                'is_active',
                'shopify_synced_at',
            ]);
        });

        Schema::table('shopify_products', function (Blueprint $table) {
            if (Schema::hasColumn('shopify_products', 'inventory_item_id')) {
                $table->dropColumn('inventory_item_id');
            }
        });
    }
};
