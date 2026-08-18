<?php

declare(strict_types=1);

namespace App\Support;

trait ReplacesTemplateVariables
{
    /**
     * Replace {{key}} placeholders in a template string.
     *
     * @param  array<string, mixed>  $data
     */
    protected function replaceVariables(string $content, array $data): string
    {
        $replacements = [];

        foreach ($data as $key => $value) {
            $replacements['{{'.$key.'}}'] = (string) $value;
        }

        return strtr($content, $replacements);
    }
}
