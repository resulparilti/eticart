<?php

declare(strict_types=1);

/**
 * EtiCart — Önbellek temizleme (Terminal/artisan gerekmez). İş bitince silin.
 * https://alanadiniz.com/clear-cache.php?run=1
 */

ini_set('display_errors', '1');
error_reporting(E_ALL);

$basePath = dirname(__DIR__);
$run = isset($_GET['run']) && $_GET['run'] === '1';

header('Content-Type: text/html; charset=utf-8');
echo '<!DOCTYPE html><html lang="tr"><head><meta charset="utf-8"><title>Önbellek temizle</title>';
echo '<style>body{font-family:Arial,sans-serif;max-width:640px;margin:48px auto;padding:0 16px}pre{background:#f8f9fa;padding:12px}</style></head><body>';
echo '<h1>EtiCart — Önbellek temizle</h1>';

if (! $run) {
    echo '<p>Laravel config/route/view önbelleğini siler.</p>';
    echo '<p><a href="?run=1" style="display:inline-block;background:#0d6efd;color:#fff;padding:10px 16px;border-radius:6px;text-decoration:none">Temizle</a></p>';
    echo '</body></html>';
    exit;
}

$removed = [];
$errors = [];

$cacheDir = $basePath.'/bootstrap/cache';
if (is_dir($cacheDir)) {
    foreach (glob($cacheDir.'/*.php') ?: [] as $file) {
        if (@unlink($file)) {
            $removed[] = 'bootstrap/cache/'.basename($file);
        } else {
            $errors[] = 'Silinemedi: '.$file;
        }
    }
}

$viewsDir = $basePath.'/storage/framework/views';
if (is_dir($viewsDir)) {
    foreach (glob($viewsDir.'/*.php') ?: [] as $file) {
        if (@unlink($file)) {
            $removed[] = 'storage/framework/views/'.basename($file);
        }
    }
}

$dataCache = $basePath.'/storage/framework/cache/data';
if (is_dir($dataCache)) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dataCache, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        /** @var SplFileInfo $item */
        $path = $item->getPathname();
        if ($item->isDir()) {
            @rmdir($path);
        } else {
            @unlink($path);
        }
    }
    $removed[] = 'storage/framework/cache/data/*';
}

echo '<p><strong>'.count($removed).'</strong> öğe temizlendi.</p>';
if ($removed !== []) {
    echo '<pre>'.htmlspecialchars(implode("\n", array_slice($removed, 0, 50)), ENT_QUOTES, 'UTF-8').'</pre>';
}
if ($errors !== []) {
    echo '<p style="color:#dc3545"><strong>Hatalar:</strong></p><pre>'.htmlspecialchars(implode("\n", $errors), ENT_QUOTES, 'UTF-8').'</pre>';
}

$migrateOutput = '';
try {
    require $basePath.'/vendor/autoload.php';
    $app = require $basePath.'/bootstrap/app.php';
    $kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();
    $kernel->call('migrate', ['--force' => true]);
    $migrateOutput = $kernel->output();
} catch (Throwable $e) {
    $migrateOutput = 'Migration hatası: '.$e->getMessage();
}

echo '<h2>Veritabanı migration</h2>';
echo '<pre>'.htmlspecialchars($migrateOutput !== '' ? $migrateOutput : '(çıktı yok)', ENT_QUOTES, 'UTF-8').'</pre>';

echo '<p>Şimdi <a href="/login">/login</a> sayfasını deneyin veya <a href="/health.php">health.php</a> ile kontrol edin.</p>';
echo '<p><small>İş bitince <code>clear-cache.php</code> dosyasını silin.</small></p>';
echo '</body></html>';
