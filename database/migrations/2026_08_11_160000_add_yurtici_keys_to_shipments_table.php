<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->string('cargo_key', 40)->nullable()->after('tracking_number')->index();
            $table->string('cargo_job_id', 50)->nullable()->after('cargo_key');
            $table->json('provider_payload')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropIndex(['cargo_key']);
            $table->dropColumn(['cargo_key', 'cargo_job_id', 'provider_payload']);
        });
    }
};
