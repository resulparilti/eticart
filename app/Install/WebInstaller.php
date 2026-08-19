<?php

declare(strict_types=1);

namespace App\Install;

use App\Models\Setting;
use App\Models\User;
use App\Services\OutboundIpService;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Throwable;

final class WebInstaller
{
    private string $basePath;

    public function __construct(?string $basePath = null)
    {
        $this->basePath = $basePath ?? dirname(__DIR__, 2);
    }

    public function lockPath(): string
    {
        return $this->basePath.'/storage/app/installed.json';
    }

    public function isInstalled(): bool
    {
        return is_file($this->lockPath());
    }

    /**
     * @return array<int, array{label: string, ok: bool, hint: string}>
     */
    public function requirements(): array
    {
        $checks = [];

        $checks[] = [
            'label' => 'PHP '.PHP_VERSION.' (8.1+ gerekli)',
            'ok' => version_compare(PHP_VERSION, '8.1.0', '>='),
            'hint' => 'cPanel → Select PHP Version → 8.1 veya 8.2',
        ];

        foreach (['pdo', 'pdo_mysql', 'mbstring', 'openssl', 'tokenizer', 'xml', 'ctype', 'json', 'fileinfo', 'curl'] as $ext) {
            $checks[] = [
                'label' => "PHP eklentisi: {$ext}",
                'ok' => extension_loaded($ext),
                'hint' => 'Hosting desteğinden eklentiyi açmasını isteyin.',
            ];
        }

        $checks[] = [
            'label' => 'vendor/ klasörü',
            'ok' => is_dir($this->basePath.'/vendor'),
            'hint' => 'ZIP tam açılmamış olabilir.',
        ];

        $checks[] = [
            'label' => 'storage/ yazılabilir',
            'ok' => $this->isWritable($this->basePath.'/storage'),
            'hint' => 'File Manager → storage → İzinler 755/775',
        ];

        $checks[] = [
            'label' => 'bootstrap/cache/ yazılabilir',
            'ok' => $this->isWritable($this->basePath.'/bootstrap/cache'),
            'hint' => 'bootstrap/cache izinlerini 755/775 yapın.',
        ];

        $checks[] = [
            'label' => 'app/ okunabilir',
            'ok' => is_readable($this->basePath.'/app/View/Components/GuestLayout.php'),
            'hint' => 'fix-permissions.php çalıştırın veya app/ klasörü izinlerini düzeltin (klasör 755).',
        ];

        $checks[] = [
            'label' => 'public/build/ (CSS/JS)',
            'ok' => is_readable($this->basePath.'/public/build/manifest.json'),
            'hint' => 'ZIP içinde public/build olmalı; yoksa npm run build çıktısını yükleyin.',
        ];

        return $checks;
    }

    public function requirementsMet(): bool
    {
        foreach ($this->requirements() as $check) {
            if (! $check['ok']) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, string>  $input
     */
    public function writeEnvFile(array $input): void
    {
        $templatePath = $this->basePath.'/env.production.example';
        if (! is_file($templatePath)) {
            $templatePath = $this->basePath.'/deploy/cpanel/env.production.example';
        }

        $template = is_file($templatePath)
            ? (string) file_get_contents($templatePath)
            : $this->defaultEnvTemplate();

        $replacements = [
            'APP_NAME' => $this->quoteEnv($input['app_name'] ?? 'EtiCart'),
            'APP_URL' => $input['app_url'] ?? '',
            'DB_HOST' => $input['db_host'] ?? 'localhost',
            'DB_PORT' => $input['db_port'] ?? '3306',
            'DB_DATABASE' => $input['db_database'] ?? '',
            'DB_USERNAME' => $input['db_username'] ?? '',
            'DB_PASSWORD' => $this->quoteEnv($input['db_password'] ?? '', true),
            'MAIL_FROM_ADDRESS' => $this->quoteEnv($input['mail_from'] ?? ('noreply@'.($input['app_domain'] ?? 'localhost'))),
            'SHOPIFY_APP_URL' => $input['app_url'] ?? '',
        ];

        $env = $template;
        foreach ($replacements as $key => $value) {
            if ($key === 'DB_PASSWORD') {
                $env = preg_replace('/^DB_PASSWORD=.*/m', 'DB_PASSWORD='.$value, $env) ?? $env;
                continue;
            }
            if ($key === 'APP_NAME') {
                $env = preg_replace('/^APP_NAME=.*/m', 'APP_NAME='.$value, $env) ?? $env;
                continue;
            }
            if ($key === 'MAIL_FROM_ADDRESS') {
                $env = preg_replace('/^MAIL_FROM_ADDRESS=.*/m', 'MAIL_FROM_ADDRESS='.$value, $env) ?? $env;
                continue;
            }
            $env = preg_replace('/^'.preg_quote($key, '/').'=.*/m', $key.'='.$value, $env) ?? $env;
        }

        $providedKey = trim((string) ($input['app_key'] ?? ''));
        $env = preg_replace('/^APP_KEY=.*/m', 'APP_KEY='.$providedKey, $env) ?? $env;
        $env = preg_replace('/^APP_DEBUG=.*/m', 'APP_DEBUG=false', $env) ?? $env;
        $env = preg_replace('/^APP_ENV=.*/m', 'APP_ENV=production', $env) ?? $env;

        if (! str_contains($env, 'SCHEDULE_CRON_MINUTES=')) {
            $env .= "\nSCHEDULE_CRON_MINUTES=15\n";
        }

        if (file_put_contents($this->basePath.'/.env', $env) === false) {
            throw new \RuntimeException('.env dosyası yazılamadı. Kök klasör izinlerini kontrol edin.');
        }
    }

    public function bootstrapLaravel(): ConsoleKernel
    {
        if (! is_file($this->basePath.'/vendor/autoload.php')) {
            throw new \RuntimeException('vendor/ bulunamadı.');
        }

        require_once $this->basePath.'/vendor/autoload.php';

        /** @var \Illuminate\Foundation\Application $app */
        $app = require $this->basePath.'/bootstrap/app.php';

        /** @var ConsoleKernel $kernel */
        $kernel = $app->make(ConsoleKernel::class);
        $kernel->bootstrap();

        return $kernel;
    }

    /**
     * @param  array<string, mixed>  $admin
     * @return array<string, mixed>
     */
    public function install(array $admin): array
    {
        $log = [];
        $restore = (bool) ($admin['restore'] ?? false);

        if (trim((string) config('app.key')) === '') {
            Artisan::call('key:generate', ['--force' => true]);
            $log[] = 'Yeni APP_KEY oluşturuldu. Kargo API şifreleri çözülemezse paneldan yeniden girin.';
        } else {
            $log[] = 'Mevcut APP_KEY kullanıldı (şifreli kargo bilgileri korunur).';
        }

        DB::connection()->getPdo();
        $log[] = 'Veritabanı bağlantısı başarılı.';

        $existing = $restore || $this->databaseLooksRestored();

        Artisan::call('migrate', ['--force' => true]);
        $log[] = $existing
            ? 'Mevcut veritabanı: yalnızca eksik tablolar/sütunlar eklendi. Ayarlar korundu.'
            : 'Veritabanı tabloları oluşturuldu.';

        if ($existing) {
            $log[] = 'Seed atlandı — Shopify / UyumSoft / kargo / mail ayarları veritabanında bırakıldı.';
        } else {
            Artisan::call('db:seed', ['--force' => true]);
            $log[] = 'Varsayılan ayarlar yüklendi.';
        }

        $adminEmail = trim((string) ($admin['email'] ?? ''));
        $adminPassword = (string) ($admin['password'] ?? '');
        $createdAdmin = false;

        if ($adminEmail !== '' && $adminPassword !== '') {
            $role = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

            $user = User::query()->updateOrCreate(
                ['email' => $adminEmail],
                [
                    'name' => (string) ($admin['name'] ?? 'Admin'),
                    'password' => Hash::make($adminPassword),
                    'email_verified_at' => now(),
                    'is_active' => true,
                ]
            );

            if (! $user->hasRole('admin')) {
                $user->assignRole($role);
            }

            $createdAdmin = true;
            $log[] = 'Yönetici hesabı kaydedildi.';
        } elseif ($existing && User::query()->exists()) {
            $log[] = 'Mevcut kullanıcılar korundu. Eski e-posta ve şifre ile giriş yapın.';
        } else {
            throw new \RuntimeException('Veritabanında kullanıcı yok. Yedeği import ettiğinizden emin olun veya yeni yönetici bilgisi girin.');
        }

        try {
            Artisan::call('storage:link');
            $log[] = 'storage:link tamamlandı.';
        } catch (Throwable $e) {
            $log[] = 'storage:link atlandı ('.$e->getMessage().').';
        }

        try {
            Artisan::call('config:cache');
            Artisan::call('route:cache');
            $log[] = 'Config ve route önbelleği oluşturuldu.';
        } catch (Throwable $e) {
            $log[] = 'Config/route cache atlandı ('.$e->getMessage().').';
        }

        try {
            Artisan::call('view:cache');
            $log[] = 'View önbelleği oluşturuldu.';
        } catch (Throwable $e) {
            $log[] = 'View cache atlandı ('.$e->getMessage().').';
        }

        $outboundIp = null;
        try {
            $outboundIp = app(OutboundIpService::class)->ip(true);
        } catch (Throwable) {
            // ignore
        }

        $meta = [
            'installed_at' => now()->toIso8601String(),
            'app_url' => config('app.url'),
            'admin_email' => $createdAdmin ? $adminEmail : (string) (User::query()->value('email') ?? ''),
            'restored_database' => $existing,
            'cron_command' => $this->cronCommand(),
            'php_binary' => PHP_BINARY,
            'base_path' => $this->basePath,
            'outbound_ip' => $outboundIp,
        ];

        $dir = dirname($this->lockPath());
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($this->lockPath(), json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return [
            'log' => $log,
            'meta' => $meta,
            'admin_email' => $createdAdmin ? $adminEmail : '',
            'admin_password' => $createdAdmin ? $adminPassword : '',
            'created_admin' => $createdAdmin,
            'restored_database' => $existing,
        ];
    }

    public function databaseLooksRestored(): bool
    {
        try {
            if (! Schema::hasTable('users')) {
                return false;
            }

            if (User::query()->exists()) {
                return true;
            }

            return Schema::hasTable('settings') && Setting::query()->exists();
        } catch (Throwable) {
            return false;
        }
    }

    public function cronCommand(): string
    {
        $cronMin = \App\Support\SyncIntervalOptions::minCronMinutes();
        $php = $this->resolveCronPhpBinary();
        $base = $this->basePath;
        $log = $base.'/storage/logs/cron.log';

        return "*/{$cronMin} * * * * cd {$base} && {$php} artisan schedule:run >> {$log} 2>&1";
    }

    /**
     * cPanel cron için CLI PHP yolu (web SAPI yolundan daha güvenilir olabilir).
     */
    private function resolveCronPhpBinary(): string
    {
        $candidates = array_values(array_filter([
            (string) env('CRON_PHP_BINARY', ''),
            '/usr/local/bin/php',
            '/usr/bin/php',
            '/opt/cpanel/ea-php81/root/usr/bin/php',
            '/opt/alt/php81/usr/bin/php',
            PHP_BINARY ?: '',
        ]));

        foreach ($candidates as $path) {
            if ($path !== '' && is_file($path)) {
                return $path;
            }
        }

        return 'php';
    }

    public function basePath(): string
    {
        return $this->basePath;
    }

    private function isWritable(string $path): bool
    {
        if (! is_dir($path)) {
            return false;
        }

        $test = $path.'/.write-test-'.bin2hex(random_bytes(4));
        $ok = @file_put_contents($test, 'ok') !== false;
        if ($ok) {
            @unlink($test);
        }

        return $ok;
    }

    private function quoteEnv(string $value, bool $alwaysQuote = false): string
    {
        if ($value === '') {
            return '';
        }

        if ($alwaysQuote || preg_match('/[\s#="\'\\\\]/', $value)) {
            return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
        }

        return $value;
    }

    private function defaultEnvTemplate(): string
    {
        return <<<'ENV'
APP_NAME=EtiCart
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=

LOG_CHANNEL=stack
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=
DB_USERNAME=
DB_PASSWORD=

CACHE_DRIVER=file
QUEUE_CONNECTION=database
SESSION_DRIVER=file
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax

SCHEDULE_CRON_MINUTES=15

MAIL_MAILER=smtp
MAIL_FROM_ADDRESS=
MAIL_FROM_NAME="${APP_NAME}"

SHOPIFY_STORE_URL=
SHOPIFY_ACCESS_TOKEN=
SHOPIFY_API_VERSION=2024-01
SHOPIFY_APP_URL=
SHOPIFY_API_KEY=
SHOPIFY_API_SECRET=
ENV;
    }
}
