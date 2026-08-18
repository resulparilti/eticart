<?php

declare(strict_types=1);

/**
 * EtiCart — ZIP sonrası izin düzeltme (vendor gerekmez, Terminal gerekmez).
 * Tarayıcıda bir kez açın: https://alanadiniz.com/fix-permissions.php
 * İşlem bitince bu dosyayı silin.
 */

ini_set('display_errors', '1');
error_reporting(E_ALL);

$basePath = dirname(__DIR__);

$targets = [
    'app',
    'bootstrap',
    'config',
    'database',
    'resources',
    'routes',
    'vendor',
    'storage',
    'public',
];

function fp_html(string $title, string $body, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="tr"><head><meta charset="utf-8"><title>'.htmlspecialchars($title, ENT_QUOTES, 'UTF-8').'</title>';
    echo '<style>body{font-family:Arial,sans-serif;max-width:720px;margin:48px auto;padding:0 16px;color:#222;line-height:1.5}';
    echo '.ok{border:1px solid #badbcc;background:#d1e7dd;padding:16px;border-radius:8px;margin:16px 0}';
    echo '.warn{border:1px solid #ffe69c;background:#fff3cd;padding:16px;border-radius:8px;margin:16px 0}';
    echo '.err{border:1px solid #f5c2c7;background:#f8d7da;padding:16px;border-radius:8px;margin:16px 0}';
    echo 'code,pre{background:#f8f9fa;padding:2px 6px;border-radius:4px;font-size:13px}pre{overflow:auto;padding:12px}';
    echo 'table{border-collapse:collapse;width:100%;font-size:13px;margin:12px 0}th,td{border:1px solid #dee2e6;padding:6px 8px;text-align:left}';
    echo '.btn{display:inline-block;background:#0d6efd;color:#fff;padding:10px 18px;border-radius:6px;text-decoration:none;border:none;cursor:pointer;font-size:15px}';
    echo '</style></head><body>';
    echo '<h1>'.htmlspecialchars($title, ENT_QUOTES, 'UTF-8').'</h1>'.$body;
    echo '</body></html>';
    exit;
}

function fp_octal(int $mode): string
{
    return substr(sprintf('%o', $mode), -4);
}

function fp_type(string $path): string
{
    return is_dir($path) ? 'klasör' : 'dosya';
}

/** @return array{dirs:int, files:int, errors:array<int, string>} */
function fp_fix_tree(string $root): array
{
    $stats = ['dirs' => 0, 'files' => 0, 'errors' => []];

    if (! file_exists($root)) {
        $stats['errors'][] = 'Yol yok: '.$root;

        return $stats;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        /** @var SplFileInfo $item */
        $path = $item->getPathname();
        $target = 0755;

        if (@chmod($path, $target)) {
            if ($item->isDir()) {
                $stats['dirs']++;
            } else {
                $stats['files']++;
            }
        } else {
            $stats['errors'][] = 'chmod başarısız: '.$path.' (mevcut: '.fp_octal((int) fileperms($path)).')';
        }
    }

    $rootMode = 0755;
    if (@chmod($root, $rootMode)) {
        if (is_dir($root)) {
            $stats['dirs']++;
        } else {
            $stats['files']++;
        }
    } else {
        $stats['errors'][] = 'chmod başarısız (kök): '.$root;
    }

    return $stats;
}

/** @return list<array{path:string, type:string, perm:string, readable:bool}> */
function fp_sample_checks(string $basePath): array
{
    $samples = [
        $basePath.'/vendor/autoload.php',
        $basePath.'/vendor/symfony/deprecation-contracts/function.php',
        $basePath.'/app/View/Components/GuestLayout.php',
        $basePath.'/app/Install/WebInstaller.php',
        $basePath.'/config/app.php',
        $basePath.'/routes/web.php',
        $basePath.'/storage',
        $basePath.'/bootstrap/cache',
    ];

    $rows = [];
    foreach ($samples as $path) {
        $exists = file_exists($path);
        $rows[] = [
            'path' => str_replace($basePath.'/', '', $path),
            'type' => $exists ? fp_type($path) : '—',
            'perm' => $exists ? fp_octal((int) fileperms($path)) : '—',
            'readable' => $exists ? is_readable($path) : false,
        ];
    }

    return $rows;
}

$rows = fp_sample_checks($basePath);

$diag = '<p>ZIP açıldıktan sonra tüm klasör ve dosyalar <strong>755</strong> olmalı. '
    .'Klasörler 644 kalırsa <em>Permission denied</em> alırsınız.</p>';

$diag .= '<table><thead><tr><th>Yol</th><th>Tür</th><th>İzin</th><th>Okunabilir?</th></tr></thead><tbody>';
foreach ($rows as $row) {
    $ok = $row['readable'] ? '✓' : '✗';
    $diag .= '<tr><td><code>'.htmlspecialchars($row['path'], ENT_QUOTES, 'UTF-8').'</code></td>';
    $diag .= '<td>'.htmlspecialchars($row['type'], ENT_QUOTES, 'UTF-8').'</td>';
    $diag .= '<td><code>'.htmlspecialchars($row['perm'], ENT_QUOTES, 'UTF-8').'</code></td>';
    $diag .= '<td>'.$ok.'</td></tr>';
}
$diag .= '</tbody></table>';

$run = isset($_GET['run']) && $_GET['run'] === '1';

if (! $run) {
    $body = $diag;
    $body .= '<div class="warn"><strong>Not:</strong> Bu script tüm klasör ve dosyaları <code>755</code> yapar. '
        .'Sadece kurulum / güncelleme sırasında kullanın; bitince <code>fix-permissions.php</code> dosyasını silin.</div>';
    $body .= '<p><a class="btn" href="?run=1">İzinleri otomatik düzelt</a></p>';
    $body .= '<p><small>Alternatif: FileZilla ile FTP bağlanıp site kökünde recursive chmod: klasör 755, dosya 644.</small></p>';
    fp_html('EtiCart — İzin düzeltme', $body);
}

$results = [];
$totalDirs = 0;
$totalFiles = 0;
$allErrors = [];

foreach ($targets as $rel) {
    $full = $basePath.'/'.$rel;
    if (! file_exists($full)) {
        $allErrors[] = 'Atlandı (yok): '.$rel;
        continue;
    }
    $stats = fp_fix_tree($full);
    $totalDirs += $stats['dirs'];
    $totalFiles += $stats['files'];
    $allErrors = array_merge($allErrors, $stats['errors']);
    $results[] = $rel.' → '.$stats['dirs'].' klasör, '.$stats['files'].' dosya';
}

$artisan = $basePath.'/artisan';
if (is_file($artisan) && @chmod($artisan, 0755)) {
    $totalFiles++;
    $results[] = 'artisan → 755';
}

$after = fp_sample_checks($basePath);
$autoloadOk = is_readable($basePath.'/vendor/autoload.php');
$appOk = is_readable($basePath.'/app/View/Components/GuestLayout.php');

$body = $diag;

if ($autoloadOk && $appOk) {
    $body .= '<div class="ok"><strong>Tamamlandı.</strong> '
        .'Toplam: <strong>'.$totalDirs.'</strong> klasör, <strong>'.$totalFiles.'</strong> dosya (izin 755).<br>';
    $body .= '<ul><li>'.implode('</li><li>', array_map('htmlspecialchars', $results)).'</li></ul>';
    $body .= '<p>Güncelleme ise <a href="clear-cache.php?run=1">clear-cache.php?run=1</a> çalıştırın. İlk kurulumsa <a href="setup.php">setup.php</a>.</p>';
    $body .= '<p><strong>Güvenlik:</strong> <code>fix-permissions.php</code> dosyasını silin.</p></div>';
} else {
    $body .= '<div class="err"><strong>İzinler kısmen düzeltildi ama hâlâ okunamayan dosya var.</strong><br>';
    $body .= 'Toplam: '.$totalDirs.' klasör, '.$totalFiles.' dosya.<br>';
    $body .= 'Muhtemel neden: dosya sahibi (owner) web kullanıcısından farklı. '
        .'ZIP\'i File Manager ile <strong>Extract</strong> ederek yeniden açmayı deneyin veya hosting desteğine yazın.</div>';
}

if ($allErrors !== []) {
    $body .= '<div class="warn"><strong>chmod hataları ('.count($allErrors).'):</strong><pre>'
        .htmlspecialchars(implode("\n", array_slice($allErrors, 0, 30)), ENT_QUOTES, 'UTF-8');
    if (count($allErrors) > 30) {
        $body .= "\n… ve ".(count($allErrors) - 30).' tane daha';
    }
    $body .= '</pre></div>';
}

$body .= '<h2>Düzeltme sonrası kontrol</h2><table><thead><tr><th>Yol</th><th>Tür</th><th>İzin</th><th>Okunabilir?</th></tr></thead><tbody>';
foreach ($after as $row) {
    $ok = $row['readable'] ? '✓' : '✗';
    $body .= '<tr><td><code>'.htmlspecialchars($row['path'], ENT_QUOTES, 'UTF-8').'</code></td>';
    $body .= '<td>'.htmlspecialchars($row['type'], ENT_QUOTES, 'UTF-8').'</td>';
    $body .= '<td><code>'.htmlspecialchars($row['perm'], ENT_QUOTES, 'UTF-8').'</code></td>';
    $body .= '<td>'.$ok.'</td></tr>';
}
$body .= '</tbody></table>';

fp_html('EtiCart — İzin düzeltme sonucu', $body);
