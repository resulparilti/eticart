<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name')->nullable();
            $table->string('user_email')->nullable();
            $table->string('action', 50);
            $table->string('module', 50)->nullable();
            $table->string('description', 1000);
            $table->string('route_name')->nullable();
            $table->string('method', 10)->nullable();
            $table->string('path', 500)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->nullableMorphs('subject');
            $table->json('properties')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_name', 'created_at']);
            $table->index(['action', 'created_at']);
            $table->index(['module', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
