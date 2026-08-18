<?php

declare(strict_types=1);

namespace App\Support;

class SyncIntervalOptions
{
    /**
     * @return array<string, array<int, int>>
     */
    public static function all(): array
    {
        $mode = self::deploymentMode();

        return config("eticart.interval_options.{$mode}", config('eticart.interval_options.vps', []));
    }

    public static function deploymentMode(): string
    {
        $mode = strtolower(trim((string) config('eticart.deployment', 'vps')));

        return in_array($mode, ['vps', 'shared'], true) ? $mode : 'vps';
    }

    public static function isVps(): bool
    {
        return self::deploymentMode() === 'vps';
    }

    /**
     * @return array<int, int>
     */
    public static function orders(): array
    {
        return self::all()['orders'] ?? [1, 2, 5, 10, 15];
    }

    /**
     * @return array<int, int>
     */
    public static function products(): array
    {
        return self::all()['products'] ?? [5, 10, 15, 30, 60];
    }

    /**
     * @return array<int, int>
     */
    public static function stock(): array
    {
        return self::all()['stock'] ?? [1, 2, 5, 10, 15];
    }

    /**
     * @return array<int, int>
     */
    public static function cargo(): array
    {
        return self::all()['cargo'] ?? [5, 10, 15, 30, 60];
    }

    public static function minCronMinutes(): int
    {
        return max(1, (int) config('eticart.schedule_cron_minutes', self::isVps() ? 1 : 15));
    }

    /**
     * @return array<int, int>
     */
    public static function allowedValues(string $group): array
    {
        return match ($group) {
            'orders' => self::orders(),
            'products' => self::products(),
            'stock' => self::stock(),
            'cargo' => self::cargo(),
            default => [15],
        };
    }

    public static function validateRule(string $group): string
    {
        $values = implode(',', self::allowedValues($group));

        return "required|integer|in:{$values}";
    }
}
