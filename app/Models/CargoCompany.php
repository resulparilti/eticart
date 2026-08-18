<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CargoCompany extends Model
{
    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'provider_type',
        'api_key',
        'api_secret',
        'username',
        'password',
        'is_active',
        'is_default',
        'settings',
    ];

    /**
     * @var array<string, string>
     */
    protected $hidden = [
        'api_key',
        'api_secret',
        'password',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'settings' => 'array',
        'api_key' => 'encrypted',
        'api_secret' => 'encrypted',
        'password' => 'encrypted',
    ];

    /**
     * Shipments for this company.
     */
    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    public function normalizedProvider(): string
    {
        return strtolower(trim((string) $this->provider_type));
    }

    /**
     * Host-independent public logo path.
     */
    public function logoUrl(): ?string
    {
        $provider = $this->normalizedProvider();
        $relative = match ($provider) {
            'yurtici' => 'images/cargo/yurtici.svg',
            'aras' => 'images/cargo/aras.svg',
            'mng' => 'images/cargo/mng.svg',
            'ptt' => 'images/cargo/ptt.svg',
            default => null,
        };

        if ($relative === null || ! is_file(public_path($relative))) {
            return null;
        }

        return '/'.$relative;
    }

    public function brandColor(): string
    {
        return match ($this->normalizedProvider()) {
            'yurtici' => '#E30613',
            'aras' => '#E87722',
            'mng' => '#0033A0',
            'ptt' => '#C4A000',
            default => '#111827',
        };
    }

    /**
     * Şifreli alanın veritabanında dolu olup olmadığını decrypt etmeden kontrol eder.
     */
    public function hasStoredCredential(string $attribute): bool
    {
        if (! in_array($attribute, ['password', 'api_key', 'api_secret'], true)) {
            return false;
        }

        return filled($this->getRawOriginal($attribute));
    }

    /**
     * Şifreli alanı güvenli okur; bozuk kayıtta null döner (500 yerine).
     */
    public function readCredential(string $attribute): ?string
    {
        if (! $this->hasStoredCredential($attribute)) {
            return null;
        }

        try {
            $value = $this->getAttribute($attribute);

            return filled($value) ? (string) $value : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Firma API kimlik bilgileri tanımlı mı?
     */
    public function isApiReady(): bool
    {
        try {
            return app(\App\Services\CargoService::class)->resolveProvider($this)->isConfigured();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Aktif ve API’si tanımlı kargo firmaları.
     *
     * @return \Illuminate\Support\Collection<int, self>
     */
    public static function apiReady(): \Illuminate\Support\Collection
    {
        return static::query()
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()
            ->filter(static fn (self $company): bool => $company->isApiReady())
            ->values();
    }
}
