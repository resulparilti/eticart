<?php

declare(strict_types=1);

/**
 * EtiCart — Cron durumu (Terminal gerekmez). Kurulum sonrası silinebilir.
 * https://alanadiniz.com/cron-status.php
 */

ini_set('display_errors', '1');
error_reporting(E_ALL);

$basePath = dirname(__DIR__);

header('Content-Type: text/html; charset=utf-8');
echo '<!DOCTYPE html><html lang="tr"><head><meta charset="utf-8"><title>Cron durumu</title>';
echo '<style>body{font-family:Arial,sans-serif;max-width:760px;margin:32px auto;padding:0 16px;line-height:1.5}';
echo '.ok{color:#198754}.bad{color:#dc3545}.warn{color:#856404}code,pre{background:#f8f9fa;padding:2px 6px;border-radius:4px}pre{padding:12px;overflow:auto;font-size:12px}</style></head><body>';
echo '<h1>EtiCart — Cron durumu</h1>';

$heartbeat = null;
$heartbeatHuman = '—';
$cronLog = '';
$recommended = '';
$phpCandidates = [];

if (is_readable($basePath.'/vendor/autoload.php')) {
    try {
        require $basePath.'/vendor/autoload.php';
        $app = require $basePath.'/bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        $raw = \App\Models\Setting::getValue('cron_last_heartbeat');
        if (is_string($raw) && $raw !== '') {
            $heartbeat = \Illuminate\Support\Carbon::parse($raw);
            $heartbeatHuman = $heartbeat->timezone(config('app.timezone'))->format('d.m.Y H:i:s');
        }

        $installer = new \App\Install\WebInstaller($basePath);
        $recommended = $installer->cronCommand();
    } catch (Throwable $e) {
        echo '<p class="bad">Laravel yüklenemedi: '.htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8').'</p>';
    }
}

$logFile = $basePath.'/storage/logs/cron.log';
if (is_readable($logFile)) {
    $lines = file($logFile, FILE_IGNORE_NEW_LINES);
    if (is_array($lines)) {
        $cronLog = implode("\n", array_slice($lines, -15));
    }
}

foreach ([
    '/usr/local/bin/php',
    '/usr/bin/php',
    '/opt/cpanel/ea-php81/root/usr/bin/php',
    '/opt/alt/php81/usr/bin/php',
] as $p) {
    $phpCandidates[] = $p.': '.(is_file($p) ? 'var' : 'yok');
}

echo '<h2>Son cron heartbeat</h2>';
if ($heartbeat) {
    $minutesAgo = $heartbeat->diffInMinutes(now());
    $class = $minutesAgo <= 20 ? 'ok' : 'bad';
    echo '<p class="'.$class.'"><strong>'.$heartbeatHuman.'</strong> ('.$minutesAgo.' dk önce)</p>';
    if ($minutesAgo > 20) {
        echo '<p class="warn">20 dakikadan eskiyse cron muhtemelen çalışmıyor veya 15 dk aralıktan daha seyrek tetikleniyor.</p>';
    } else {
        echo '<p class="ok">Cron son zamanlarda tetiklenmiş görünüyor.</p>';
    }
} else {
    echo '<p class="bad">Henüz heartbeat yok — <code>schedule:run</code> hiç başarılı çalışmamış olabilir.</p>';
}

echo '<h2>Kuyruk durumu</h2>';
$pendingJobs = -1;
$failedJobs = -1;
try {
    $pendingJobs = (int) \Illuminate\Support\Facades\DB::table('jobs')->count();
    $failedJobs = (int) \Illuminate\Support\Facades\DB::table('failed_jobs')->count();
    echo '<p>Bekleyen iş: <strong>'.$pendingJobs.'</strong> · Başarısız iş: <strong>'.$failedJobs.'</strong></p>';
    if ($failedJobs > 0) {
        echo '<p class="warn">Başarısız kuyruk kayıtları var — <code>/admin/queue</code> veya <code>storage/logs/laravel.log</code> kontrol edin.</p>';
    }
} catch (Throwable) {
    echo '<p class="warn">Kuyruk tabloları okunamadı (migration eksik olabilir).</p>';
}

echo '<h2>Önerilen cPanel cron komutu</h2>';
echo '<pre>'.htmlspecialchars($recommended !== '' ? $recommended : '*/15 * * * * cd '.$basePath.' && php artisan schedule:run >> '.$basePath.'/storage/logs/cron.log 2>&1', ENT_QUOTES, 'UTF-8').'</pre>';
echo '<p><small>cPanel → Cron Jobs → <strong>Every 15 minutes</strong> (5 dk çoğu paylaşımlı hostta desteklenmez)</small></p>';

echo '<h2>PHP yolları (sunucuda)</h2><ul>';
foreach ($phpCandidates as $line) {
    echo '<li><code>'.htmlspecialchars($line, ENT_QUOTES, 'UTF-8').'</code></li>';
}
echo '</ul>';

echo '<h2>storage/logs/cron.log (son 15 satır)</h2>';
echo '<p class="warn"><small><strong>Not:</strong> <code>No scheduled commands are ready to run</code> eski sürümde '
    .'saat :00/:15/:30/:45 dışında görülür; cron yine de çalışıyordur. Güncel Kernel.php ile heartbeat satırı gelmelidir.</small></p>';
echo '<pre>'.htmlspecialchars($cronLog !== '' ? $cronLog : '(dosya yok veya boş)', ENT_QUOTES, 'UTF-8').'</pre>';

echo '<h2>İşlem geçmişi neden boş olabilir?</h2><ul>';
echo '<li>Cron çalışsa bile Shopify/UyumSoft ayarları eksikse senkron job atlanır.</li>';
echo '<li>Aralık ayarları minimum 15 dk; ilk kayıt sonrası bir sonraki periyotta görünür.</li>';
echo '<li>Manuel “Senkronize et” butonları anında geçmişe yazar; cron ayrı kontrol edilir.</li>';
echo '</ul>';

echo '<p><small>Doğrulama bitince <code>cron-status.php</code> dosyasını silin.</small></p>';
echo '</body></html>';
