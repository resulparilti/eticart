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
        Schema::create('shopify_customers', function (Blueprint $table) {
            $table->id();
            $table->string('shopify_customer_id')->nullable()->unique();
            $table->string('email')->nullable()->index();
            $table->string('phone')->nullable()->index();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('full_name')->nullable()->index();
            $table->string('company')->nullable();
            $table->text('address')->nullable();
            $table->string('city')->nullable();
            $table->string('province')->nullable();
            $table->string('country')->nullable();
            $table->string('zip')->nullable();
            $table->unsignedInteger('orders_count')->default(0);
            $table->decimal('total_spent', 12, 2)->default(0);
            $table->string('currency', 10)->nullable();
            $table->boolean('tax_exempt')->default(false);
            $table->boolean('verified_email')->default(false);
            $table->string('state')->nullable();
            $table->json('tags')->nullable();
            $table->json('addresses')->nullable();
            $table->json('raw')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('shopify_created_at')->nullable();
            $table->timestamp('shopify_updated_at')->nullable();
            $table->timestamp('last_order_at')->nullable();
            $table->timestamp('last_sync')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('shopify_orders', function (Blueprint $table) {
            $table->foreignId('customer_id')
                ->nullable()
                ->after('user_id')
                ->constrained('shopify_customers')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shopify_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_id');
        });

        Schema::dropIfExists('shopify_customers');
    }
};
