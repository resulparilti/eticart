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
        Schema::create('shopify_products', function (Blueprint $table) {
            $table->id();
            $table->string('shopify_product_id')->index();
            $table->string('shopify_variant_id')->nullable()->index();
            $table->string('title');
            $table->string('sku')->nullable()->index();
            $table->decimal('price', 12, 2)->default(0);
            $table->integer('stock')->default(0);
            $table->foreignId('uyumsoft_product_id')
                ->nullable()
                ->constrained('uyumsoft_products')
                ->nullOnDelete();
            $table->timestamp('last_sync')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['shopify_product_id', 'shopify_variant_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shopify_products');
    }
};
