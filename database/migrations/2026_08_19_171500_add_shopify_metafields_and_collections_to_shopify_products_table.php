<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopify_products', function (Blueprint $table) {
            if (! Schema::hasColumn('shopify_products', 'metafields')) {
                $table->json('metafields')->nullable()->after('images');
            }
            if (! Schema::hasColumn('shopify_products', 'collections')) {
                $table->json('collections')->nullable()->after('metafields');
            }
        });
    }

    public function down(): void
    {
        Schema::table('shopify_products', function (Blueprint $table) {
            foreach (['metafields', 'collections'] as $column) {
                if (Schema::hasColumn('shopify_products', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
