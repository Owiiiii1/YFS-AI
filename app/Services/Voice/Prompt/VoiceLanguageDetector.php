<?php

namespace App\Services\Voice\Prompt;

final class VoiceLanguageDetector
{
    public function detect(string $text, ?string $hint = null): string
    {
        $hint = strtolower(trim((string) $hint));
        if (in_array($hint, ['en', 'ru', 'uk'], true)) {
            return $hint;
        }

        if (preg_match('/[іїєґІЇЄҐ]/u', $text) === 1) {
            return 'uk';
        }
        if (preg_match('/[А-Яа-яЁё]/u', $text) === 1) {
            return 'ru';
        }

        return 'en';
    }
}
