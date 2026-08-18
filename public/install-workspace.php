<?php

declare(strict_types=1);

/**
 * EtiCart — Kullanıcı çalışma alanı tablolarını kurar (todos/notes/kanban).
 * https://alanadiniz.com/install-workspace.php?run=1
 * İş bitince bu dosyayı silin.
 */

ini_set('display_errors', '1');
error_reporting(E_ALL);

$basePath = dirname(__DIR__);
$run = isset($_GET['run']) && $_GET['run'] === '1';

header('Content-Type: text/html; charset=utf-8');
echo '<!DOCTYPE html><html lang="tr"><head><meta charset="utf-8"><title>Workspace kurulum</title>';
echo '<style>body{font-family:Arial,sans-serif;max-width:720px;margin:48px auto;padding:0 16px}pre{background:#f8f9fa;padding:12px;white-space:pre-wrap}</style></head><body>';
echo '<h1>EtiCart — Workspace tabloları</h1>';

if (! $run) {
    echo '<p><code>user_todos</code>, <code>user_notes</code>, <code>kanban_columns</code>, <code>kanban_cards</code> tablolarını oluşturur.</p>';
    echo '<p><a href="?run=1" style="display:inline-block;background:#0d6efd;color:#fff;padding:10px 16px;border-radius:6px;text-decoration:none">Kurulumu çalıştır</a></p>';
    echo '</body></html>';
    exit;
}

try {
    require $basePath.'/vendor/autoload.php';
    $app = require $basePath.'/bootstrap/app.php';
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();

    $lines = [];

    // Önce migration dosyası varsa artisan migrate dene
    $migrationPath = $basePath.'/database/migrations/2026_08_13_120000_create_user_workspace_tables.php';
    if (is_file($migrationPath)) {
        $kernel->call('migrate', [
            '--force' => true,
            '--path' => 'database/migrations/2026_08_13_120000_create_user_workspace_tables.php',
        ]);
        $lines[] = 'artisan migrate:';
        $lines[] = trim($kernel->output()) ?: '(çıktı yok)';
    } else {
        $lines[] = 'Migration dosyası bulunamadı, Schema ile oluşturuluyor…';
    }

    if (! Illuminate\Support\Facades\Schema::hasTable('user_todos')) {
        Illuminate\Support\Facades\Schema::create('user_todos', function (Illuminate\Database\Schema\Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->boolean('is_done')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->index(['user_id', 'is_done', 'position']);
        });
        $lines[] = 'user_todos oluşturuldu';
    } else {
        $lines[] = 'user_todos zaten var';
    }

    if (! Illuminate\Support\Facades\Schema::hasTable('user_notes')) {
        Illuminate\Support\Facades\Schema::create('user_notes', function (Illuminate\Database\Schema\Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->mediumText('body')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'archived_at', 'updated_at']);
        });
        $lines[] = 'user_notes oluşturuldu';
    } else {
        $lines[] = 'user_notes zaten var';
    }

    if (! Illuminate\Support\Facades\Schema::hasTable('kanban_columns')) {
        Illuminate\Support\Facades\Schema::create('kanban_columns', function (Illuminate\Database\Schema\Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('color', 20)->default('#6c757d');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->index(['user_id', 'position']);
        });
        $lines[] = 'kanban_columns oluşturuldu';
    } else {
        $lines[] = 'kanban_columns zaten var';
    }

    if (! Illuminate\Support\Facades\Schema::hasTable('kanban_cards')) {
        Illuminate\Support\Facades\Schema::create('kanban_cards', function (Illuminate\Database\Schema\Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('kanban_column_id')->constrained('kanban_columns')->cascadeOnDelete();
            $table->string('title');
            $table->mediumText('body')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->index(['kanban_column_id', 'position']);
            $table->index(['user_id', 'kanban_column_id']);
        });
        $lines[] = 'kanban_cards oluşturuldu';
    } else {
        $lines[] = 'kanban_cards zaten var';
    }

    echo '<p><strong>Tamamlandı.</strong></p>';
    echo '<pre>'.htmlspecialchars(implode("\n", $lines), ENT_QUOTES, 'UTF-8').'</pre>';
    echo '<p><a href="/notes">/notes</a> · <a href="/todos">/todos</a> · <a href="/kanban">/kanban</a></p>';
    echo '<p><small>İş bitince <code>install-workspace.php</code> dosyasını silin.</small></p>';
} catch (Throwable $e) {
    echo '<p style="color:#dc3545"><strong>Hata:</strong> '.htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8').'</p>';
    echo '<pre>'.htmlspecialchars($e->getTraceAsString(), ENT_QUOTES, 'UTF-8').'</pre>';
}

echo '</body></html>';
