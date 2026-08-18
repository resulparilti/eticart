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
            $table->string('invoice_path')->nullable()->after('notes');
            $table->string('invoice_original_name')->nullable()->after('invoice_path');
            $table->timestamp('invoice_uploaded_at')->nullable()->after('invoice_original_name');
        });
    }

    public function down(): void
    {
        Schema::table('shopify_orders', function (Blueprint $table) {
            $table->dropColumn(['invoice_path', 'invoice_original_name', 'invoice_uploaded_at']);
        });
    }
};
