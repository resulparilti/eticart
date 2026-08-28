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
            $table->string('shopify_content_hash', 64)->nullable()->after('synced_at');
            $table->string('uyumsoft_content_hash', 64)->nullable()->after('uyumsoft_last_error');
            $table->boolean('uyumsoft_needs_update')->default(false)->after('uyumsoft_content_hash');
            $table->boolean('uyumsoft_invoice_locked')->default(false)->after('uyumsoft_needs_update');

            $table->index('uyumsoft_needs_update');
            $table->index('uyumsoft_invoice_locked');
        });

        Schema::create('shopify_order_archives', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('local_order_id')->nullable()->index();
            $table->string('shopify_order_id')->index();
            $table->string('order_number')->index();
            $table->string('customer_name')->nullable();
            $table->string('customer_email')->nullable();
            $table->string('customer_phone')->nullable();
            $table->decimal('total_price', 12, 2)->nullable();
            $table->string('currency', 10)->nullable();
            $table->string('payment_status')->nullable();
            $table->string('fulfillment_status')->nullable();
            $table->string('reason', 64)->index();
            $table->json('snapshot');
            $table->timestamp('archived_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopify_order_archives');

        Schema::table('shopify_orders', function (Blueprint $table) {
            $table->dropIndex(['uyumsoft_needs_update']);
            $table->dropIndex(['uyumsoft_invoice_locked']);
            $table->dropColumn([
                'shopify_content_hash',
                'uyumsoft_content_hash',
                'uyumsoft_needs_update',
                'uyumsoft_invoice_locked',
            ]);
        });
    }
};
