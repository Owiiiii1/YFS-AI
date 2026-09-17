<?php

namespace App\Services\Identity;

final class IdentityNameNormalizer
{
    public static function fold(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (\class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($value, \Normalizer::FORM_KC);
            if (is_string($normalized) && $normalized !== '') {
                $value = $normalized;
            }
        }

        $value = mb_strtolower($value);
        $value = strtr($value, [
            'ё' => 'е',
            'ґ' => 'г',
            '’' => ' ',
            '‘' => ' ',
            'ʼ' => ' ',
            '`' => ' ',
            "'" => ' ',
            '´' => ' ',
        ]);
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    /**
     * @return list<string>
     */
    public static function words(string $value): array
    {
        $folded = self::fold($value);
        if ($folded === '') {
            return [];
        }

        $parts = preg_split('/\s+/u', $folded, -1, PREG_SPLIT_NO_EMPTY);

        return is_array($parts) ? array_values($parts) : [];
    }

    public static function containsCyrillic(string $value): bool
    {
        return (bool) preg_match('/\p{Cyrillic}/u', $value);
    }
}
