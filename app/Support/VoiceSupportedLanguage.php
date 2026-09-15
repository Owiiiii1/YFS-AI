<?php

namespace App\Support;

final class VoiceSupportedLanguage
{
    public const SUPPORTED = ['en', 'ru', 'uk'];

    /**
     * Map an ElevenLabs / stored language tag to en|ru|uk, or null if unsupported.
     */
    public static function tryNormalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $raw = strtolower(trim($value));
        if ($raw === '') {
            return null;
        }

        $raw = str_replace('_', '-', $raw);

        $aliases = [
            'en' => 'en',
            'en-us' => 'en',
            'en-gb' => 'en',
            'eng' => 'en',
            'english' => 'en',
            'ru' => 'ru',
            'ru-ru' => 'ru',
            'rus' => 'ru',
            'russian' => 'ru',
            'uk' => 'uk',
            'uk-ua' => 'uk',
            'ukr' => 'uk',
            'ua' => 'uk',
            'ukrainian' => 'uk',
        ];

        if (isset($aliases[$raw])) {
            return $aliases[$raw];
        }

        $primary = explode('-', $raw)[0];

        return in_array($primary, self::SUPPORTED, true) ? $primary : null;
    }

    public static function isSupported(?string $value): bool
    {
        return self::tryNormalize($value) !== null;
    }
}
