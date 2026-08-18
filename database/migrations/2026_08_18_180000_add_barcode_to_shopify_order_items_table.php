<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopify_order_items', function (Blueprint $table): void {
            $table->string('barcode', 64)->nullable()->after('sku');
        });
    }

    public function down(): void
    {
        Schema::table('shopify_order_items', function (Blueprint $table): void {
            $table->dropColumn('barcode');
        });
    }
};
