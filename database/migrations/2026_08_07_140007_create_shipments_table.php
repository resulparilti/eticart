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
        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shopify_order_id')
                ->constrained('shopify_orders')
                ->cascadeOnDelete();
            $table->foreignId('cargo_company_id')
                ->nullable()
                ->constrained('cargo_companies')
                ->nullOnDelete();
            $table->string('order_number')->index();
            $table->string('tracking_number')->nullable()->index();
            $table->string('tracking_url')->nullable();
            $table->string('status')->default('pending')->index();
            $table->string('receiver_name')->nullable();
            $table->string('receiver_phone')->nullable();
            $table->text('receiver_address')->nullable();
            $table->string('receiver_city')->nullable();
            $table->decimal('weight', 8, 2)->nullable();
            $table->decimal('cargo_cost', 12, 2)->nullable();
            $table->decimal('insurance', 12, 2)->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            $table->string('label_path')->nullable();
            $table->string('invoice_path')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shipments');
    }
};
