<?php

namespace App\Services\Voice\Calls;

final class VoiceTranscriptSanitizer
{
    /**
     * Allowlisted ElevenLabs language/voice wrapper names.
     * Matched case-insensitively; unknown HTML-like tags are left untouched.
     *
     * @var list<string>
     */
    private const WRAPPER_TAGS = [
        'break',
        'en',
        'eng',
        'english',
        'emphasis',
        'emotion',
        'lang',
        'prosody',
        'ru',
        'rus',
        'russian',
        'speak',
        'style',
        'ua',
        'uk',
        'ukr',
        'ukrainian',
        'voice',
    ];

    public function sanitize(?string $message): string
    {
        $text = trim((string) $message);
        if ($text === '') {
            return '';
        }

        $text = $this->stripAllowedTags($text);
        $text = $this->stripLeadingStyleAnnotation($text);
        $text = trim(preg_replace('/[ \t]+/u', ' ', $text) ?? $text);
        $text = trim(preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text);

        return $text;
    }

    /**
     * @return list<string>
     */
    public function extractLanguageCodes(?string $message): array
    {
        $text = (string) $message;
        if ($text === '') {
            return [];
        }

        $pattern = '/<('.implode('|', array_map(
            static fn (string $tag): string => preg_quote($tag, '/'),
            self::WRAPPER_TAGS,
        )).')(?:\s[^>]*)?>/iu';

        if (preg_match_all($pattern, $text, $matches) === false) {
            return [];
        }

        $codes = [];
        foreach ($matches[1] as $tag) {
            $code = $this->tagToLanguage((string) $tag);
            if ($code !== null) {
                $codes[] = $code;
            }
        }

        return array_values(array_unique($codes));
    }

    private function stripAllowedTags(string $text): string
    {
        $names = implode('|', array_map(
            static fn (string $tag): string => preg_quote($tag, '/'),
            self::WRAPPER_TAGS,
        ));

        $previous = null;
        for ($i = 0; $i < 4 && $text !== $previous; $i++) {
            $previous = $text;
            $text = preg_replace('/<\/?(?:'.$names.')(?:\s[^>]*)?\/?>/iu', '', $text) ?? $text;
        }

        return $text;
    }

    private function stripLeadingStyleAnnotation(string $text): string
    {
        $stripped = preg_replace('/^\s*[\p{L}][\p{L}\p{M}\s-]{0,38}>\s*/u', '', $text);

        return is_string($stripped) ? $stripped : $text;
    }

    private function tagToLanguage(string $tag): ?string
    {
        return match (strtolower(trim($tag))) {
            'en', 'eng', 'english' => 'en',
            'ru', 'rus', 'russian' => 'ru',
            'uk', 'ukr', 'ua', 'ukrainian' => 'uk',
            default => null,
        };
    }
}
