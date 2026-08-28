<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'key',
        'value',
        'category',
        'label',
    ];

    /**
     * Get a setting value by key.
     */
    public static function getValue(string $key, mixed $default = null): mixed
    {
        try {
            $settings = Cache::remember('eticart.settings', 3600, function () {
                return static::query()->pluck('value', 'key')->toArray();
            });
        } catch (\Throwable) {
            try {
                $value = static::query()->where('key', $key)->value('value');

                return $value ?? $default;
            } catch (\Throwable) {
                return $default;
            }
        }

        return $settings[$key] ?? $default;
    }

    /**
     * Set a setting value by key.
     */
    public static function setValue(string $key, mixed $value, string $category = 'general', ?string $label = null): self
    {
        $setting = static::query()->updateOrCreate(
            ['key' => $key],
            [
                'value' => is_scalar($value) || $value === null ? $value : json_encode($value),
                'category' => $category,
                'label' => $label,
            ]
        );

        try {
            Cache::forget('eticart.settings');
        } catch (\Throwable) {
            // cPanel'de cache klasörü yazılamazsa kayıt yine geçerli olsun.
        }

        return $setting;
    }

    /**
     * Panel / mail brand name (Genel Ayarlar → Sistem adı).
     */
    public static function appName(?string $default = null): string
    {
        $fallback = $default ?? (string) config('app.name', 'EtiCart');
        if ($fallback === '') {
            $fallback = 'EtiCart';
        }

        try {
            $name = trim((string) static::getValue('general_app_name', ''));
        } catch (\Throwable) {
            return $fallback;
        }

        return $name !== '' ? $name : $fallback;
    }

    /**
     * Müşteri maillerinde görünen firma adı (Genel Ayarlar → Firma adı).
     */
    public static function companyName(?string $default = null): string
    {
        try {
            $name = trim((string) static::getValue('general_company_name', ''));
        } catch (\Throwable) {
            $name = '';
        }

        if ($name !== '') {
            return $name;
        }

        return static::appName($default);
    }

    /**
     * Vitrin / site adresi (https).
     */
    public static function websiteUrl(): string
    {
        try {
            $url = trim((string) static::getValue('general_website_url', ''));
        } catch (\Throwable) {
            $url = '';
        }

        if ($url === '') {
            try {
                $shop = trim((string) static::getValue('shopify_store_url', ''));
            } catch (\Throwable) {
                $shop = '';
            }
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

    /**
     * Shopify müşteri hesap sayfası.
     */
    public static function accountUrl(): string
    {
        $site = static::websiteUrl();

        return $site !== '' ? $site.'/account' : '';
    }

    /**
     * Clear settings cache.
     */
    protected static function booted(): void
    {
        static::saved(function (): void {
            try {
                Cache::forget('eticart.settings');
            } catch (\Throwable) {
            }
        });
        static::deleted(function (): void {
            try {
                Cache::forget('eticart.settings');
            } catch (\Throwable) {
            }
        });
    }
}
