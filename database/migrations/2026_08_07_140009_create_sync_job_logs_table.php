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
        Schema::create('sync_job_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sync_job_id')
                ->constrained('sync_jobs')
                ->cascadeOnDelete();
            $table->string('status')->index();
            $table->text('message')->nullable();
            $table->unsignedInteger('synced_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->decimal('duration', 8, 2)->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sync_job_logs');
    }
};
