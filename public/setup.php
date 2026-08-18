<?php

declare(strict_types=1);

/**
 * EtiCart — Tek tık cPanel kurulum sihirbazı (Terminal gerekmez).
 * ZIP yükledikten sonra: https://alanadiniz.com/setup.php
 */

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

$basePath = dirname(__DIR__);

function setup_fatal(string $title, string $message): never
{
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="tr"><head><meta charset="utf-8"><title>Kurulum hatası</title>';
    echo '<style>body{font-family:Arial,sans-serif;max-width:640px;margin:48px auto;padding:0 16px;color:#222}';
    echo '.box{border:1px solid #f5c2c7;background:#f8d7da;padding:16px;border-radius:8px}code{background:#fff;padding:2px 6px}</style></head><body>';
    echo '<h1>'.$title.'</h1><div class="box">'.$message.'</div></body></html>';
    exit;
}

if (version_compare(PHP_VERSION, '8.1.0', '<')) {
    setup_fatal(
        'PHP sürümü yetersiz',
        '<p>PHP <strong>8.1+</strong> gerekli. Şu an: <code>'.PHP_VERSION.'</code></p>'
        .'<p>cPanel → <strong>Select PHP Version</strong> → 8.1 veya 8.2 seçin, sonra sayfayı yenileyin.</p>'
    );
}

$autoload = $basePath.'/vendor/autoload.php';
if (! is_file($autoload)) {
    setup_fatal(
        'vendor klasörü eksik',
        '<p><code>vendor/autoload.php</code> bulunamadı.</p>'
        .'<p>ZIP içeriğini <code>eticart/</code> köküne tam açtığınızdan emin olun. Document Root yalnızca <code>public</code> klasörüne işaret etmeli.</p>'
        .'<p>Beklenen yol: <code>'.htmlspecialchars($autoload, ENT_QUOTES, 'UTF-8').'</code></p>'
    );
}

try {
    require $autoload;
} catch (Throwable $e) {
    $msg = htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    $extra = '';
    if (stripos($e->getMessage(), 'Permission denied') !== false || stripos($e->getMessage(), 'Failed opening required') !== false) {
        $extra = '<hr><p><strong>Muhtemel neden:</strong> Klasörler <code>644</code> kalmış olabilir — klasörler <code>755</code> olmalı (dosyalar <code>644</code>).</p>'
            .'<p><strong>En kolay çözüm:</strong> Önce şu sayfayı açın (vendor gerekmez): '
            .'<a href="/fix-permissions.php"><strong>fix-permissions.php</strong></a></p>'
            .'<p>Manuel (File Manager): <code>vendor</code> → Change Permissions → Recurse → klasörler <code>755</code>, dosyalar <code>644</code>. '
            .'Aynı işlemi <code>storage</code> ve <code>bootstrap/cache</code> için yapın.</p>'
            .'<p class="mb-0">Toplu izin yoksa FileZilla FTP ile bağlanıp recursive chmod yapabilirsiniz.</p>';
    }
    setup_fatal('Autoload hatası', '<p>'.$msg.'</p>'.$extra);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

define('LARAVEL_START', microtime(true));

try {
    $installer = new \App\Install\WebInstaller($basePath);
} catch (Throwable $e) {
    setup_fatal('Kurulum sınıfı yüklenemedi', '<p>'.htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8').'</p>');
}

$scheme = (! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
$detectedUrl = $scheme.'://'.$host;
$selfUrl = $detectedUrl.'/setup.php';

function setup_csrf(): string
{
    if (empty($_SESSION['setup_csrf'])) {
        $_SESSION['setup_csrf'] = bin2hex(random_bytes(16));
    }

    return $_SESSION['setup_csrf'];
}

function setup_verify_csrf(): bool
{
    $token = (string) ($_POST['_csrf'] ?? '');

    return $token !== '' && hash_equals((string) ($_SESSION['setup_csrf'] ?? ''), $token);
}

/**
 * @param  array<string, mixed>  $data
 */
function setup_render(string $title, string $content, array $data = []): never
{
    $step = (int) ($data['step'] ?? 1);
    $appName = (string) ($data['app_name'] ?? 'EtiCart');

    http_response_code((int) ($data['status'] ?? 200));
    ?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?> — Kurulum</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f0f2f5; min-height: 100vh; }
        .setup-wrap { max-width: 720px; margin: 48px auto; }
        .setup-card { border: 0; box-shadow: 0 8px 32px rgba(0,0,0,.08); border-radius: 12px; }
        .setup-brand { font-weight: 800; letter-spacing: -.02em; }
        .step-dot { width: 28px; height: 28px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 700; }
        .step-dot.active { background: #0d6efd; color: #fff; }
        .step-dot.done { background: #198754; color: #fff; }
        .step-dot.pending { background: #dee2e6; color: #6c757d; }
        code.copy { cursor: pointer; user-select: all; }
    </style>
</head>
<body>
<div class="setup-wrap">
    <div class="text-center mb-4">
        <div class="setup-brand fs-3"><?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?></div>
        <div class="text-muted small">cPanel kurulum sihirbazı</div>
    </div>

    <div class="d-flex justify-content-center gap-3 mb-4">
        <?php foreach ([1 => 'Kontrol', 2 => 'Ayarlar', 3 => 'Tamam'] as $num => $label): ?>
            <?php
            $class = 'pending';
            if ($step === $num) {
                $class = 'active';
            } elseif ($step > $num) {
                $class = 'done';
            }
            ?>
            <div class="text-center">
                <span class="step-dot <?= $class ?>"><?= $num ?></span>
                <div class="small mt-1 text-muted"><?= $label ?></div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="card setup-card">
        <div class="card-body p-4">
            <h1 class="h4 mb-3"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
            <?= $content ?>
        </div>
    </div>
</div>
</body>
</html>
    <?php
    exit;
}

// Zaten kurulu
if ($installer->isInstalled()) {
    $meta = json_decode((string) file_get_contents($installer->lockPath()), true) ?: [];
    $body = '<div class="alert alert-success">Sistem zaten kurulu.</div>';
    $body .= '<p><a class="btn btn-primary" href="/login">Giriş sayfasına git</a></p>';
    $body .= '<p class="small text-muted mb-0">Güvenlik için <code>public/setup.php</code> dosyasını silin.</p>';
    if (! empty($meta['cron_command'])) {
        $body .= '<hr><p class="small mb-1">Cron komutu:</p><pre class="small bg-light p-2 rounded"><code>'.htmlspecialchars((string) $meta['cron_command'], ENT_QUOTES, 'UTF-8').'</code></pre>';
    }
    setup_render('Kurulum tamamlandı', $body, ['step' => 3, 'app_name' => $meta['app_name'] ?? 'EtiCart']);
}

$requirements = $installer->requirements();
$reqOk = $installer->requirementsMet();

// POST — kurulumu çalıştır
if ($_SERVER['REQUEST_METHOD'] === 'POST' && setup_verify_csrf()) {
    $errors = [];

    $appName = trim((string) ($_POST['app_name'] ?? 'EtiCart'));
    $appUrl = rtrim(trim((string) ($_POST['app_url'] ?? $detectedUrl)), '/');
    $dbHost = trim((string) ($_POST['db_host'] ?? 'localhost'));
    $dbPort = trim((string) ($_POST['db_port'] ?? '3306'));
    $dbName = trim((string) ($_POST['db_database'] ?? ''));
    $dbUser = trim((string) ($_POST['db_username'] ?? ''));
    $dbPass = (string) ($_POST['db_password'] ?? '');
    $adminName = trim((string) ($_POST['admin_name'] ?? 'Admin'));
    $adminEmail = trim((string) ($_POST['admin_email'] ?? ''));
    $adminPassword = (string) ($_POST['admin_password'] ?? '');
    $adminPassword2 = (string) ($_POST['admin_password_confirmation'] ?? '');
    $restoreDatabase = (string) ($_POST['restore_database'] ?? '') === '1';
    $appKey = trim((string) ($_POST['app_key'] ?? ''));

    if ($dbName === '' || $dbUser === '') {
        $errors[] = 'Veritabanı adı ve kullanıcı zorunludur.';
    }
    if ($restoreDatabase) {
        if ($adminEmail !== '' || $adminPassword !== '' || $adminPassword2 !== '') {
            if ($adminEmail === '' || ! filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Yeni yönetici oluşturuyorsanız geçerli e-posta girin.';
            }
            if (strlen($adminPassword) < 8) {
                $errors[] = 'Yönetici şifresi en az 8 karakter olmalıdır.';
            }
            if ($adminPassword !== $adminPassword2) {
                $errors[] = 'Yönetici şifreleri eşleşmiyor.';
            }
        }
    } else {
        if ($adminEmail === '' || ! filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Geçerli bir yönetici e-postası girin.';
        }
        if (strlen($adminPassword) < 8) {
            $errors[] = 'Yönetici şifresi en az 8 karakter olmalıdır.';
        }
        if ($adminPassword !== $adminPassword2) {
            $errors[] = 'Yönetici şifreleri eşleşmiyor.';
        }
    }
    if ($appKey !== '' && ! str_starts_with($appKey, 'base64:')) {
        $errors[] = 'APP_KEY "base64:" ile başlamalıdır. Eski .env dosyasındaki satırı aynen yapıştırın.';
    }
    if (! $reqOk) {
        $errors[] = 'Sunucu gereksinimleri karşılanmıyor.';
    }

    if ($errors !== []) {
        $errHtml = '<div class="alert alert-danger"><ul class="mb-0">';
        foreach ($errors as $err) {
            $errHtml .= '<li>'.htmlspecialchars($err, ENT_QUOTES, 'UTF-8').'</li>';
        }
        $errHtml .= '</ul></div>';
        // fall through to form with errors - set flag
        $_GET['errors_html'] = $errHtml;
    } else {
        try {
            $installer->writeEnvFile([
                'app_name' => $appName !== '' ? $appName : 'EtiCart',
                'app_url' => $appUrl,
                'app_domain' => parse_url($appUrl, PHP_URL_HOST) ?: $host,
                'db_host' => $dbHost,
                'db_port' => $dbPort,
                'db_database' => $dbName,
                'db_username' => $dbUser,
                'db_password' => $dbPass,
                'app_key' => $appKey,
            ]);

            $installer->bootstrapLaravel();

            $result = $installer->install([
                'name' => $adminName,
                'email' => $adminEmail,
                'password' => $adminPassword,
                'restore' => $restoreDatabase,
            ]);

            $meta = $result['meta'];
            $cron = htmlspecialchars((string) $meta['cron_command'], ENT_QUOTES, 'UTF-8');
            $loginUrl = htmlspecialchars(rtrim($appUrl, '/').'/login', ENT_QUOTES, 'UTF-8');
            $email = htmlspecialchars($result['admin_email'], ENT_QUOTES, 'UTF-8');
            $pass = htmlspecialchars($result['admin_password'], ENT_QUOTES, 'UTF-8');
            $outbound = htmlspecialchars((string) ($meta['outbound_ip'] ?? ''), ENT_QUOTES, 'UTF-8');

            $logHtml = '<ul class="small">';
            foreach ($result['log'] as $line) {
                $logHtml .= '<li>'.htmlspecialchars($line, ENT_QUOTES, 'UTF-8').'</li>';
            }
            $logHtml .= '</ul>';

            $body = '<div class="alert alert-success"><strong>Kurulum başarıyla tamamlandı.</strong></div>';
            $body .= $logHtml;
            if (! empty($result['created_admin'])) {
                $body .= '<hr><h2 class="h6">Giriş bilgileri</h2>';
                $body .= '<table class="table table-sm"><tbody>';
                $body .= '<tr><th>Panel adresi</th><td><a href="'.$loginUrl.'">'.$loginUrl.'</a></td></tr>';
                $body .= '<tr><th>E-posta</th><td><code>'.$email.'</code></td></tr>';
                $body .= '<tr><th>Şifre</th><td><code>'.$pass.'</code></td></tr>';
                $body .= '</tbody></table>';
            } else {
                $body .= '<hr><h2 class="h6">Giriş</h2>';
                $body .= '<p>Shopify, UyumSoft ve kargo ayarları veritabanından geldi. <strong>Eski panel hesabınızla</strong> giriş yapın.</p>';
                $body .= '<p class="mb-0"><a href="'.$loginUrl.'">'.$loginUrl.'</a></p>';
            }
            $body .= '<hr><h2 class="h6">cPanel Cron (15 dk — kopyalayıp yapıştırın)</h2>';
            $body .= '<pre class="bg-light p-3 rounded small user-select-all"><code id="cron-cmd">'.$cron.'</code></pre>';
            $body .= '<p class="small text-muted">cPanel → Cron Jobs → Common Settings: <strong>Every 15 minutes</strong>, komut alanına yukarıdaki satırı yapıştırın. PHP yolu farklıysa hosting desteğine sorun.</p>';
            if ($outbound !== '') {
                $body .= '<hr><h2 class="h6">Yurtiçi Kargo çıkış IP</h2>';
                $body .= '<p class="mb-0">Whitelist için: <code class="user-select-all">'.$outbound.'</code></p>';
            }
            $body .= '<hr><div class="alert alert-warning small mb-0"><strong>Önemli:</strong> File Manager\'dan silin: '
                .'<code>public/setup.php</code>, <code>fix-permissions.php</code>, <code>health.php</code>, <code>clear-cache.php</code></div>';
            $body .= '<div class="mt-3"><a class="btn btn-primary" href="/login">Panele giriş yap</a></div>';

            setup_render('Kurulum tamamlandı', $body, ['step' => 3, 'app_name' => $appName]);
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            $body = '<div class="alert alert-danger"><strong>Kurulum hatası:</strong><br>'
                .htmlspecialchars($msg, ENT_QUOTES, 'UTF-8').'</div>';

            if (stripos($msg, 'Permission denied') !== false || stripos($msg, 'Failed to open stream') !== false) {
                $body .= '<p class="small"><strong>Bu bir dosya izni hatası</strong> (veritabanı değil). '
                    .'Önce <a href="/fix-permissions.php?run=1"><strong>fix-permissions.php</strong></a> ile tüm proje izinlerini düzeltin, '
                    .'sonra kurulumu tekrar deneyin.</p>';
            } elseif (stripos($msg, 'SQLSTATE') !== false || stripos($msg, 'Access denied') !== false || stripos($msg, 'Connection refused') !== false) {
                $body .= '<p class="small">Veritabanı bilgilerini ve cPanel MySQL kullanıcı yetkilerini kontrol edin.</p>';
            } else {
                $body .= '<p class="small">Sorun devam ederse hosting desteğine başvurun.</p>';
            }

            $body .= '<a href="setup.php" class="btn btn-outline-secondary">Geri dön</a>';
            setup_render('Hata', $body, ['step' => 2, 'app_name' => $appName, 'status' => 500]);
        }
    }
}

// GET — adım 1 + form
$csrf = setup_csrf();
$errorsHtml = (string) ($_GET['errors_html'] ?? '');

$reqList = '<ul class="list-group list-group-flush mb-4">';
foreach ($requirements as $check) {
    $icon = $check['ok'] ? '✅' : '❌';
    $reqList .= '<li class="list-group-item d-flex justify-content-between align-items-start px-0">';
    $reqList .= '<span>'.$icon.' '.htmlspecialchars($check['label'], ENT_QUOTES, 'UTF-8').'</span>';
    if (! $check['ok']) {
        $reqList .= '<span class="small text-muted">'.htmlspecialchars($check['hint'], ENT_QUOTES, 'UTF-8').'</span>';
    }
    $reqList .= '</li>';
}
$reqList .= '</ul>';

if (! $reqOk) {
    $body = '<div class="alert alert-warning">Kuruluma devam etmeden önce kırmızı maddeleri düzeltin.</div>';
    $body .= $reqList;
    $body .= '<button class="btn btn-outline-primary" onclick="location.reload()">Yeniden kontrol et</button>';
    setup_render('Sunucu kontrolü', $body, ['step' => 1]);
}

function old_or_post(string $key, string $default = ''): string
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        return (string) ($_POST[$key] ?? $default);
    }

    return $default;
}

$old = static fn (string $key, string $default = ''): string => htmlspecialchars(
    (string) old_or_post($key, $default),
    ENT_QUOTES,
    'UTF-8'
);

$restoreChecked = $_SERVER['REQUEST_METHOD'] !== 'POST' || (string) ($_POST['restore_database'] ?? '') === '1';
$oldCheck = $restoreChecked ? 'checked' : '';

$body = $errorsHtml;
$body .= '<div class="alert alert-info small">Önce cPanel MySQL\'e <strong>veritabanı yedeğini import</strong> edin. Shopify, UyumSoft ve kargo API bilgileri oradan gelir; bu ekranda tekrar girilmez.</div>';
$body .= $reqList;

$body .= '<form method="POST" action="setup.php">';
$body .= '<input type="hidden" name="_csrf" value="'.htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8').'">';

$body .= '<div class="form-check mb-3 p-3 border rounded bg-light">';
$body .= '<input class="form-check-input" type="checkbox" name="restore_database" value="1" id="restoreDb" '.$oldCheck.'>';
$body .= '<label class="form-check-label" for="restoreDb"><strong>Veritabanı yedeğini yükledim</strong></label>';
$body .= '<div class="small text-muted mt-1">İşaretliyse seed çalışmaz, mevcut kullanıcılar ve API ayarları korunur.</div>';
$body .= '</div>';

$body .= '<h2 class="h6 mt-2">Site</h2><div class="row g-3 mb-3">';
$body .= '<div class="col-md-6"><label class="form-label">Sistem adı</label><input type="text" name="app_name" class="form-control" value="'.$old('app_name', 'EtiCart').'" required></div>';
$body .= '<div class="col-md-6"><label class="form-label">Site adresi (APP_URL)</label><input type="url" name="app_url" class="form-control" value="'.$old('app_url', $detectedUrl).'" required></div>';
$body .= '</div>';

$body .= '<h2 class="h6">Veritabanı (cPanel MySQL)</h2><div class="row g-3 mb-3">';
$body .= '<div class="col-md-8"><label class="form-label">Sunucu</label><input type="text" name="db_host" class="form-control" value="'.$old('db_host', 'localhost').'" required></div>';
$body .= '<div class="col-md-4"><label class="form-label">Port</label><input type="text" name="db_port" class="form-control" value="'.$old('db_port', '3306').'" required></div>';
$body .= '<div class="col-md-4"><label class="form-label">Veritabanı adı</label><input type="text" name="db_database" class="form-control" value="'.$old('db_database').'" required></div>';
$body .= '<div class="col-md-4"><label class="form-label">Kullanıcı</label><input type="text" name="db_username" class="form-control" value="'.$old('db_username').'" required></div>';
$body .= '<div class="col-md-4"><label class="form-label">Şifre</label><input type="password" name="db_password" class="form-control" autocomplete="new-password"></div>';
$body .= '</div>';

$body .= '<h2 class="h6">APP_KEY (eski .env)</h2>';
$body .= '<p class="small text-muted">Kargo API şifreleri bu anahtarla şifrelenir. Yerel <code>.env</code> içindeki <code>APP_KEY=base64:...</code> satırını yapıştırın. Boş bırakırsanız yeni anahtar üretilir; kargo şifrelerini panelden yeniden girmeniz gerekir.</p>';
$body .= '<div class="mb-3"><input type="text" name="app_key" class="form-control font-monospace" placeholder="base64:...." value="'.$old('app_key').'" autocomplete="off"></div>';

$body .= '<h2 class="h6">Yönetici (isteğe bağlı — yedekte kullanıcı varsa boş bırakın)</h2><div class="row g-3 mb-3">';
$body .= '<div class="col-md-6"><label class="form-label">Ad</label><input type="text" name="admin_name" class="form-control" value="'.$old('admin_name', 'Admin').'"></div>';
$body .= '<div class="col-md-6"><label class="form-label">E-posta</label><input type="email" name="admin_email" class="form-control" value="'.$old('admin_email').'"></div>';
$body .= '<div class="col-md-6"><label class="form-label">Şifre</label><input type="password" name="admin_password" class="form-control" minlength="8" autocomplete="new-password"></div>';
$body .= '<div class="col-md-6"><label class="form-label">Şifre (tekrar)</label><input type="password" name="admin_password_confirmation" class="form-control" minlength="8" autocomplete="new-password"></div>';
$body .= '</div>';

$body .= '<button type="submit" class="btn btn-primary btn-lg w-100">Kurulumu başlat</button>';
$body .= '</form>';

setup_render('Kurulum ayarları', $body, ['step' => 2]);
