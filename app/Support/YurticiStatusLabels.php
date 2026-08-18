<?php

declare(strict_types=1);

namespace App\Support;

final class YurticiStatusLabels
{
    /**
     * @return array<string, string>
     */
    public static function operationMap(): array
    {
        return [
            'NOP' => 'Kargo işlem görmemiş — kayıt Yurtiçi sistemine ulaştı, şubede henüz barkodlanmadı',
            'UPD' => 'Güncellendi / işlem gördü',
            'IND' => 'Dağıtımda',
            'DLV' => 'Teslim edildi',
            'CNL' => 'İptal',
            'IPT' => 'İptal',
            'RTN' => 'İade',
            'RAS' => 'Alıcıya ulaşılamadı',
            'MIS' => 'Kayıp / sorunlu',
        ];
    }

    public static function operation(?string $code, ?string $fallback = null): string
    {
        $code = strtoupper(trim((string) $code));
        if ($code === '') {
            return $fallback ?: '—';
        }

        $label = self::operationMap()[$code] ?? null;

        return $label ? "{$code} — {$label}" : ($fallback ? "{$code} — {$fallback}" : $code);
    }
}
