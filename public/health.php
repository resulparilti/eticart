<?php

declare(strict_types=1);

/**
 * EtiCart — Kurulum sonrası teşhis (Terminal gerekmez). İş bitince silin.
 * https://alanadiniz.com/health.php
 */

ini_set('display_errors', '1');
error_reporting(E_ALL);

$basePath = dirname(__DIR__);

function h_html(string $title, string $body): never
{
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="tr"><head><meta charset="utf-8"><title>'.htmlspecialchars($title, ENT_QUOTES, 'UTF-8').'</title>';
    echo '<style>body{font-family:Arial,sans-serif;max-width:820px;margin:32px auto;padding:0 16px;line-height:1.5}';
    echo 'table{border-collapse:collapse;width:100%;font-size:13px;margin:12px 0}th,td{border:1px solid #ddd;padding:8px;text-align:left}';
    echo '.ok{color:#198754}.bad{color:#dc3545}pre{background:#f8f9fa;padding:12px;overflow:auto;font-size:12px}</style></head><body>';
    echo '<h1>'.htmlspecialchars($title, ENT_QUOTES, 'UTF-8').'</h1>'.$body.'</body></html>';
    exit;
}

/** @return array{ok:bool, label:string, detail:string} */
function h_row(string $label, bool $ok, string $detail = ''): array
{
    return ['ok' => $ok, 'label' => $label, 'detail' => $detail];
}

$rows = [];

$rows[] = h_row('PHP '.PHP_VERSION, version_compare(PHP_VERSION, '8.1.0', '>='));

$manifest = $basePath.'/public/build/manifest.json';
$manifestOk = is_readable($manifest);
$rows[] = h_row('public/build/manifest.json', $manifestOk, $manifestOk ? 'OK' : 'Eksik veya okunamıyor — CSS/JS yüklenmez, 500 verebilir');

$writablePaths = [
    'storage/framework/sessions' => $basePath.'/storage/framework/sessions',
    'storage/framework/views' => $basePath.'/storage/framework/views',
    'storage/logs' => $basePath.'/storage/logs',
    'bootstrap/cache' => $basePath.'/bootstrap/cache',
];
foreach ($writablePaths as $label => $path) {
    $test = $path.'/.h-test-'.bin2hex(random_bytes(3));
    $ok = @file_put_contents($test, '1') !== false;
    if ($ok) {
        @unlink($test);
    }
    $rows[] = h_row($label.' yazılabilir', $ok, $ok ? 'OK' : '755/775 gerekli');
}

$envOk = is_readable($basePath.'/.env');
$rows[] = h_row('.env okunabilir', $envOk);

$keyOk = false;
if ($envOk) {
    $env = (string) file_get_contents($basePath.'/.env');
    $keyOk = (bool) preg_match('/^APP_KEY=base64:.+/m', $env);
}
$rows[] = h_row('APP_KEY tanımlı', $keyOk);

$laravelError = null;
$loginOk = false;

if (is_readable($basePath.'/vendor/autoload.php')) {
    try {
        require $basePath.'/vendor/autoload.php';
        /** @var \Illuminate\Foundation\Application $app */
        $app = require $basePath.'/bootstrap/app.php';
        /** @var \Illuminate\Contracts\Http\Kernel $kernel */
        $kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
        $kernel->bootstrap();

        $rows[] = h_row('Laravel bootstrap', true);

        try {
            \Illuminate\Support\Facades\DB::connection()->getPdo();
            $rows[] = h_row('Veritabanı bağlantısı', true);
        } catch (Throwable $e) {
            $rows[] = h_row('Veritabanı bağlantısı', false, $e->getMessage());
        }

        try {
            $html = view('auth.login')->render();
            $loginOk = is_string($html) && str_contains($html, 'Giriş Yap');
            $rows[] = h_row('Login view render', $loginOk, $loginOk ? 'OK' : 'HTML beklenmiyor');
        } catch (Throwable $e) {
            $laravelError = $e->getMessage();
            $rows[] = h_row('Login view render', false, $laravelError);
        }
    } catch (Throwable $e) {
        $laravelError = $e->getMessage();
        $rows[] = h_row('Laravel bootstrap', false, $laravelError);
    }
} else {
    $rows[] = h_row('vendor/autoload.php', false, 'Bulunamadı');
}

$logFile = $basePath.'/storage/logs/laravel.log';
$logTail = '';
if (is_readable($logFile)) {
    $lines = file($logFile, FILE_IGNORE_NEW_LINES);
    if (is_array($lines)) {
        $logTail = implode("\n", array_slice($lines, -40));
    }
}

$table = '<table><thead><tr><th>Kontrol</th><th>Durum</th><th>Detay</th></tr></thead><tbody>';
foreach ($rows as $row) {
    $icon = $row['ok'] ? '<span class="ok">✓</span>' : '<span class="bad">✗</span>';
    $table .= '<tr><td>'.htmlspecialchars($row['label'], ENT_QUOTES, 'UTF-8').'</td><td>'.$icon.'</td>';
    $table .= '<td>'.htmlspecialchars($row['detail'], ENT_QUOTES, 'UTF-8').'</td></tr>';
}
$table .= '</tbody></table>';

$body = $table;

if ($laravelError) {
    $body .= '<h2>Muhtemel hata</h2><pre>'.htmlspecialchars($laravelError, ENT_QUOTES, 'UTF-8').'</pre>';
}

if ($logTail !== '') {
    $body .= '<h2>storage/logs/laravel.log (son 40 satır)</h2><pre>'.htmlspecialchars($logTail, ENT_QUOTES, 'UTF-8').'</pre>';
}

$body .= '<h2>Hızlı düzeltme</h2><ul>';
$body .= '<li><a href="/clear-cache.php?run=1">clear-cache.php</a> — önbellek temizle</li>';
$body .= '<li><a href="/fix-permissions.php?run=1">fix-permissions.php</a> — izinleri düzelt</li>';
$body .= '<li>File Manager → <code>public/build/</code> klasörünün yüklü olduğundan emin olun</li>';
$body .= '<li>Güncel <code>resources/views/layouts/guest.blade.php</code> dosyasını yükleyin</li>';
$body .= '</ul>';
$body .= '<p><small>Teşhis bitince <code>health.php</code> dosyasını silin.</small></p>';

if ($loginOk) {
    $body .= '<p class="ok"><strong>Login sayfası render edilebiliyor.</strong> <a href="/login">/login</a> deneyin.</p>';
}

h_html('EtiCart — Sistem teşhisi', $body);
