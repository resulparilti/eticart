<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('uyumsoft_products', function (Blueprint $table) {
            $table->string('source_hash', 64)->nullable()->after('last_sync');
            $table->index('source_hash');
        });
    }

    public function down(): void
    {
        Schema::table('uyumsoft_products', function (Blueprint $table) {
            $table->dropIndex(['source_hash']);
            $table->dropColumn('source_hash');
        });
    }
};
