<?php

declare(strict_types=1);

namespace App\Support;

final class UiTheme
{
    public const COOKIE = 'eticart-theme';

    public const DARK = 'dark';

    public const LIGHT = 'light';

    public static function fromRequest(): string
    {
        $value = request()?->cookie(self::COOKIE);

        return $value === self::DARK ? self::DARK : self::LIGHT;
    }

    public static function background(string $theme): string
    {
        return $theme === self::DARK ? '#0b1420' : '#f3f6f9';
    }
}
