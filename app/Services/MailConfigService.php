<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Throwable;

class MailConfigService
{
    /**
     * Apply DB mail settings over runtime config.
     */
    public function applyFromSettings(): void
    {
        try {
            $host = trim((string) (Setting::getValue('mail_smtp_host') ?? ''));
            $port = Setting::getValue('mail_smtp_port');
            $username = Setting::getValue('mail_smtp_username');
            $password = Setting::getValue('mail_smtp_password');
            $encryption = $this->normalizeEncryption(Setting::getValue('mail_smtp_encryption'));
            $fromName = Setting::getValue('mail_from_name');
            $mailer = (string) (Setting::getValue('mail_mailer') ?: config('mail.default', 'log'));

            if ($host !== '') {
                config([
                    'mail.mailers.smtp.host' => $host,
                    'mail.mailers.smtp.port' => (int) ($port ?: 587),
                    'mail.mailers.smtp.username' => filled($username) ? (string) $username : null,
                    'mail.mailers.smtp.password' => filled($password) ? (string) $password : null,
                    'mail.mailers.smtp.encryption' => $encryption,
                    'mail.mailers.smtp.timeout' => 20,
                ]);

                $ehloDomain = $this->ehloDomain($host);
                if ($ehloDomain !== null) {
                    config(['mail.mailers.smtp.local_domain' => $ehloDomain]);
                }
            }

            $resolvedFrom = $this->resolvedFromAddress();
            if ($resolvedFrom !== '') {
                config(['mail.from.address' => $resolvedFrom]);
            }

            if ($fromName) {
                config(['mail.from.name' => $this->safeHeaderText((string) $fromName)]);
            } else {
                config(['mail.from.name' => $this->brandName()]);
            }

            if ($mailer === 'smtp' && $host !== '') {
                config(['mail.default' => 'smtp']);
            } elseif ($mailer === 'sendmail') {
                $sendmailPath = trim((string) ini_get('sendmail_path'));
                if ($sendmailPath === '') {
                    $sendmailPath = '/usr/sbin/sendmail -t -i';
                }

                config([
                    'mail.default' => 'sendmail',
                    'mail.mailers.sendmail.path' => $sendmailPath,
                ]);
            } elseif ($mailer === 'log') {
                config(['mail.default' => 'log']);
            }

            if (app()->isBooted()) {
                try {
                    Mail::purge('smtp');
                    Mail::purge('sendmail');
                    Mail::purge('log');
                } catch (Throwable) {
                }
            }
        } catch (Throwable $e) {
            Log::channel('stack')->warning('Mail config apply failed', [
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * From address that will actually be used (aligned with SMTP auth when needed).
     *
     * Gmail/Outlook SMTP + farklı From domain'i kurumsal sunucularda SPF/DMARC
     * nedeniyle sessizce reddedilir; Gmail gelen kutusuna ise düşer.
     */
    public function resolvedFromAddress(): string
    {
        $from = trim((string) (Setting::getValue('mail_from_address') ?: config('mail.from.address', '')));
        $mailer = (string) (Setting::getValue('mail_mailer') ?: config('mail.default', 'log'));
        $username = trim((string) Setting::getValue('mail_smtp_username', ''));
        $host = strtolower((string) Setting::getValue('mail_smtp_host', ''));

        if ($mailer === 'smtp' && $username !== '' && filter_var($username, FILTER_VALIDATE_EMAIL) && $this->isSharedSmtpHost($host)) {
            $fromDomain = strtolower((string) substr((string) strrchr($from, '@'), 1));
            $userDomain = strtolower((string) substr((string) strrchr($username, '@'), 1));
            if ($fromDomain !== '' && $userDomain !== '' && $fromDomain !== $userDomain) {
                return $username;
            }
        }

        return $from;
    }

    public function fromAddressMismatch(): bool
    {
        $configured = trim((string) Setting::getValue('mail_from_address', ''));
        $resolved = $this->resolvedFromAddress();

        return $configured !== '' && $resolved !== '' && strcasecmp($configured, $resolved) !== 0;
    }

    public function isSharedSmtpHost(string $host): bool
    {
        $host = strtolower($host);

        foreach (['gmail.com', 'googlemail.com', 'smtp.google', 'outlook.com', 'office365.com', 'protection.outlook.com', 'hotmail.com', 'yahoo.com', 'sendgrid.net', 'mailgun.org'] as $needle) {
            if (str_contains($host, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether SMTP can send real mail.
     */
    public function smtpReady(): bool
    {
        return filled(Setting::getValue('mail_smtp_host'))
            && (string) Setting::getValue('mail_mailer') === 'smtp';
    }

    /**
     * Branding used by outbound HTML e-mails.
     *
     * @return array{
     *     name: string,
     *     header_bg: string,
     *     header_text: string,
     *     body_text: string,
     *     muted_text: string,
     *     link: string,
     *     button_bg: string,
     *     button_text: string,
     *     logo_path: ?string,
     *     logo_url: ?string
     * }
     */
    public function branding(): array
    {
        $logoRel = (string) Setting::getValue('mail_logo_path', '');
        $logoPath = $logoRel !== '' && Storage::disk('public')->exists($logoRel)
            ? Storage::disk('public')->path($logoRel)
            : null;
        $logoUrl = null;
        if ($logoPath) {
            $logoUrl = Storage::disk('public')->url($logoRel);
            if (! str_starts_with($logoUrl, 'http')) {
                $logoUrl = rtrim((string) config('app.url'), '/').'/'.ltrim($logoUrl, '/');
            }
            if (
                str_starts_with($logoUrl, 'http://')
                && ! str_contains($logoUrl, 'localhost')
                && ! str_contains($logoUrl, '127.0.0.1')
            ) {
                $logoUrl = 'https://'.substr($logoUrl, 7);
            }
            if (! str_starts_with($logoUrl, 'https://')) {
                $logoUrl = null;
            }
        }

        return [
            'name' => $this->brandName(),
            'header_bg' => $this->color('mail_header_bg', '#0f2a3d'),
            'header_text' => $this->color('mail_header_text', '#ffffff'),
            'body_text' => $this->color('mail_text_color', '#142433'),
            'muted_text' => $this->color('mail_muted_color', '#5b6b7c'),
            'link' => $this->color('mail_link_color', '#c45c26'),
            'button_bg' => $this->color('mail_button_bg', '#0f2a3d'),
            'button_text' => $this->color('mail_button_text', '#ffffff'),
            'logo_path' => null,
            'logo_url' => $logoUrl,
            'site_url' => $this->siteUrl(),
            'account_url' => $this->accountUrl(),
        ];
    }

    /**
     * Peş peşe mail gönderim kontrolü.
     */
    public function sendIntervalSeconds(): int
    {
        $minutes = (int) Setting::getValue('mail_send_interval_minutes', 3);
        if ($minutes < 2) {
            $minutes = 2;
        }
        if ($minutes > 10) {
            $minutes = 10;
        }

        return $minutes * 60;
    }

    public function secondsUntilAllowed(): int
    {
        if (app()->runningUnitTests()) {
            return 0;
        }

        $last = (int) Setting::getValue('mail_last_outbound_at', 0);
        if ($last <= 0) {
            return 0;
        }

        $wait = $this->sendIntervalSeconds() - (time() - $last);

        return $wait > 0 ? $wait : 0;
    }

    public function markOutboundSent(): void
    {
        try {
            Setting::setValue('mail_last_outbound_at', (string) time(), 'mail', 'Son giden mail zamanı');
        } catch (Throwable) {
        }
    }

    public function safeHeaderText(string $value): string
    {
        $value = str_replace(["\r", "\n", "\0"], ' ', $value);

        return trim($value);
    }

    private function normalizeEncryption(mixed $encryption): ?string
    {
        $value = strtolower(trim((string) $encryption));

        return in_array($value, ['tls', 'ssl'], true) ? $value : null;
    }

    private function ehloDomain(string $smtpHost): ?string
    {
        if ($this->isSharedSmtpHost($smtpHost)) {
            return null;
        }

        $from = $this->resolvedFromAddress();
        $domain = strtolower((string) substr((string) strrchr($from, '@'), 1));

        return $domain !== '' ? $domain : null;
    }

    private function color(string $key, string $default): string
    {
        $value = trim((string) Setting::getValue($key, $default));

        return preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value) === 1
            ? $value
            : $default;
    }

    public function brandName(): string
    {
        $name = trim((string) Setting::getValue('general_company_name', ''));
        if ($name === '') {
            $name = trim((string) Setting::getValue('general_app_name', ''));
        }
        if ($name === '') {
            $name = trim((string) config('app.name', ''));
        }

        return $name;
    }

    public function siteUrl(): string
    {
        $url = trim((string) Setting::getValue('general_website_url', ''));
        if ($url === '') {
            $shop = trim((string) Setting::getValue('shopify_store_url', ''));
            $shop = (string) preg_replace('#^https?://#i', '', $shop);
            $shop = rtrim($shop, '/');
            $url = $shop !== '' ? 'https://'.$shop : '';
        }

        if ($url === '') {
            return '';
        }

        if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
            $url = 'https://'.$url;
        }

        if (str_starts_with($url, 'http://') && ! str_contains($url, 'localhost')) {
            $url = 'https://'.substr($url, 7);
        }

        return rtrim($url, '/');
    }

    public function accountUrl(): string
    {
        $site = $this->siteUrl();

        return $site !== '' ? $site.'/account' : '';
    }
}
