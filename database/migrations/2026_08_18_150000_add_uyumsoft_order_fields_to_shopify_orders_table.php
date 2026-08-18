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
            $table->string('uyumsoft_order_id')->nullable()->after('shopify_pushed_at');
            $table->string('uyumsoft_invoice_id')->nullable()->after('uyumsoft_order_id');
            $table->string('uyumsoft_invoice_no')->nullable()->after('uyumsoft_invoice_id');
            $table->timestamp('uyumsoft_pushed_at')->nullable()->after('uyumsoft_invoice_no');
            $table->text('uyumsoft_last_error')->nullable()->after('uyumsoft_pushed_at');

            $table->index('uyumsoft_order_id');
            $table->index('uyumsoft_invoice_id');
        });
    }

    public function down(): void
    {
        Schema::table('shopify_orders', function (Blueprint $table) {
            $table->dropIndex(['uyumsoft_order_id']);
            $table->dropIndex(['uyumsoft_invoice_id']);
            $table->dropColumn([
                'uyumsoft_order_id',
                'uyumsoft_invoice_id',
                'uyumsoft_invoice_no',
                'uyumsoft_pushed_at',
                'uyumsoft_last_error',
            ]);
        });
    }
};
