<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('shopify_orders', function (Blueprint $table) {
            $table->boolean('shopify_needs_push')->default(false)->after('synced_at');
            $table->timestamp('shopify_pushed_at')->nullable()->after('shopify_needs_push');
            $table->index('shopify_needs_push');
        });

        DB::table('shopify_orders')
            ->whereNull('deleted_at')
            ->whereNull('shopify_pushed_at')
            ->where(function ($query): void {
                $query->whereIn('fulfillment_status', ['preparing', 'fulfilled'])
                    ->orWhereNotNull('invoice_path');
            })
            ->update(['shopify_needs_push' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shopify_orders', function (Blueprint $table) {
            $table->dropIndex(['shopify_needs_push']);
            $table->dropColumn(['shopify_needs_push', 'shopify_pushed_at']);
        });
    }
};
