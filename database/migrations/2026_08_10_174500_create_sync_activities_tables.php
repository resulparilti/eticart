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
        Schema::create('sync_activities', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 64)->index();
            $table->string('title');
            $table->string('status', 32)->default('queued')->index();
            $table->unsignedInteger('progress_current')->default(0);
            $table->unsignedInteger('progress_total')->nullable();
            $table->string('message')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('sync_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sync_activity_id')
                ->constrained('sync_activities')
                ->cascadeOnDelete();
            $table->string('level', 16)->default('info')->index();
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sync_activity_logs');
        Schema::dropIfExists('sync_activities');
    }
};
