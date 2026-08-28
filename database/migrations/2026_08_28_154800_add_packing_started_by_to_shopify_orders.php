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
            if (! Schema::hasColumn('shopify_orders', 'packing_started_by_user_id')) {
                $table->foreignId('packing_started_by_user_id')->nullable()->after('packing_photo_path')
                    ->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('shopify_orders', 'packing_started_by_name')) {
                $table->string('packing_started_by_name')->nullable()->after('packing_started_by_user_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('shopify_orders', function (Blueprint $table) {
            if (Schema::hasColumn('shopify_orders', 'packing_started_by_user_id')) {
                $table->dropConstrainedForeignId('packing_started_by_user_id');
            }
            if (Schema::hasColumn('shopify_orders', 'packing_started_by_name')) {
                $table->dropColumn('packing_started_by_name');
            }
        });
    }
};
