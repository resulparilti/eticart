<?php

declare(strict_types=1);

use App\Support\OrderMessageTemplates;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        OrderMessageTemplates::syncToDatabase();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Kullanıcı düzenlemeleri korunur.
    }
};
