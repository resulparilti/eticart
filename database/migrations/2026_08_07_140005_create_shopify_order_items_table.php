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
        Schema::create('shopify_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shopify_order_id')
                ->constrained('shopify_orders')
                ->cascadeOnDelete();
            $table->string('shopify_line_item_id')->nullable()->index();
            $table->string('product_title');
            $table->string('variant_title')->nullable();
            $table->string('sku')->nullable()->index();
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('price', 12, 2)->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shopify_order_items');
    }
};
