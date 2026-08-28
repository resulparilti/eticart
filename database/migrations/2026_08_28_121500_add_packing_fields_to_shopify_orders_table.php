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
            if (! Schema::hasColumn('shopify_orders', 'packed_at')) {
                $table->timestamp('packed_at')->nullable()->after('notes');
            }
            if (! Schema::hasColumn('shopify_orders', 'packed_by_user_id')) {
                $table->foreignId('packed_by_user_id')->nullable()->after('packed_at')
                    ->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('shopify_orders', 'packed_by_name')) {
                $table->string('packed_by_name')->nullable()->after('packed_by_user_id');
            }
            if (! Schema::hasColumn('shopify_orders', 'packing_checklist')) {
                $table->json('packing_checklist')->nullable()->after('packed_by_name');
            }
            if (! Schema::hasColumn('shopify_orders', 'packing_gift_box')) {
                $table->boolean('packing_gift_box')->default(false)->after('packing_checklist');
            }
            if (! Schema::hasColumn('shopify_orders', 'packing_gift_box_size')) {
                $table->string('packing_gift_box_size', 50)->nullable()->after('packing_gift_box');
            }
            if (! Schema::hasColumn('shopify_orders', 'packing_photo_path')) {
                $table->string('packing_photo_path')->nullable()->after('packing_gift_box_size');
            }

            $table->index('packed_at');
        });
    }

    public function down(): void
    {
        Schema::table('shopify_orders', function (Blueprint $table) {
            if (Schema::hasColumn('shopify_orders', 'packed_by_user_id')) {
                $table->dropConstrainedForeignId('packed_by_user_id');
            }
            foreach ([
                'packed_at',
                'packed_by_name',
                'packing_checklist',
                'packing_gift_box',
                'packing_gift_box_size',
                'packing_photo_path',
            ] as $column) {
                if (Schema::hasColumn('shopify_orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
