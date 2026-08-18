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
        Schema::create('uyumsoft_products', function (Blueprint $table) {
            $table->id();
            $table->string('uyumsoft_id')->unique();
            $table->string('sku')->nullable()->index();
            $table->string('title');
            $table->json('variant_info')->nullable();
            $table->decimal('original_price', 12, 2)->default(0);
            $table->integer('stock')->default(0);
            $table->boolean('synced_to_shopify')->default(false)->index();
            $table->string('shopify_id')->nullable()->index();
            $table->timestamp('last_sync')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('uyumsoft_products');
    }
};
