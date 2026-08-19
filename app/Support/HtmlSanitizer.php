<?php

declare(strict_types=1);

namespace App\Support;

final class HtmlSanitizer
{
    /**
     * Basit zengin metin içeriğini güvenli HTML'e indirger.
     */
    public static function rich(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        $trimmed = trim($html);
        if ($trimmed === '' || $trimmed === '<br>' || $trimmed === '<div><br></div>') {
            return null;
        }

        $clean = strip_tags($trimmed, '<p><br><div><span><b><strong><i><em><u><a><ul><ol><li><h2><h3><h4><h5><h6><font>');

        return $clean !== '' ? $clean : null;
    }
}
