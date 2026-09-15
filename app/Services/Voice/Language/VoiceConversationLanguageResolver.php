<?php

namespace App\Services\Voice\Language;

use App\Services\Voice\Calls\VoiceTranscriptSanitizer;
use App\Support\VoiceSupportedLanguage;

final class VoiceConversationLanguageResolver
{
    private const MIN_SUBSTANTIAL_LETTERS = 12;

    private const LAST_WINDOW = 4;

    /** @var list<string> */
    private const UK_MARKERS = [
        'дякую', 'будь ласка', 'вітаю', 'вибачте', 'потрібно', 'скільки',
        'чому', 'якщо', 'сьогодні', 'дитина', 'дитини', 'дітей', 'підкаж',
        'україн', 'заявк', 'гарна', 'гарний', 'маємо', 'після', 'зараз',
        'добрий день', 'доброго',
    ];

    /** @var list<string> */
    private const RU_MARKERS = [
        'спасибо', 'пожалуйста', 'здравствуйте', 'сколько', 'почему',
        'сегодня', 'если', 'сейчас', 'ребёнок', 'ребенок', 'ребёнка',
        'ребенка', 'хорошо', 'который', 'уже', 'очень', 'можно',
        'добрый день', 'добрый',
    ];

    public function __construct(
        private readonly VoiceTranscriptSanitizer $sanitizer,
    ) {}

    /**
     * Resolve the sustained conversation language (en|ru|uk) or null.
     *
     * @param  list<array{role?: string, speaker?: string, message?: string}>  $turns
     */
    public function resolve(mixed $rawTranscript, array $turns, ?string $fallbackLanguage = null): ?string
    {
        $signals = $this->signals($rawTranscript, $turns);
        $fromTranscript = $this->resolveFromSignals($signals);
        if ($fromTranscript !== null) {
            return $fromTranscript;
        }

        return VoiceSupportedLanguage::tryNormalize($fallbackLanguage);
    }

    /**
     * @param  list<array{role?: string, speaker?: string, message?: string}>  $turns
     * @return list<array{language: ?string, substantial: bool, opening_assistant: bool}>
     */
    private function signals(mixed $rawTranscript, array $turns): array
    {
        $items = is_array($rawTranscript) && $rawTranscript !== [] ? $rawTranscript : $turns;
        $out = [];
        $kept = 0;

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $role = strtolower(trim((string) ($item['role'] ?? $item['speaker'] ?? '')));
            $mapped = match ($role) {
                'user', 'client' => 'user',
                'agent', 'assistant' => 'agent',
                default => null,
            };
            if ($mapped === null) {
                continue;
            }

            $rawMessage = (string) ($item['message'] ?? '');
            $clean = $this->sanitizer->sanitize($rawMessage);
            if ($clean === '') {
                continue;
            }

            $tagLanguage = $this->lastTagLanguage($this->sanitizer->extractLanguageCodes($rawMessage));
            $scriptLanguage = $this->detectScriptLanguage($clean);
            $language = $tagLanguage ?? $scriptLanguage;
            $letters = $this->letterCount($clean);

            $out[] = [
                'language' => $language,
                'substantial' => $letters >= self::MIN_SUBSTANTIAL_LETTERS && $language !== null,
                'opening_assistant' => $kept === 0 && $mapped === 'agent',
            ];
            $kept++;
        }

        return $out;
    }

    /**
     * @param  list<array{language: ?string, substantial: bool, opening_assistant: bool}>  $signals
     */
    private function resolveFromSignals(array $signals): ?string
    {
        $substantial = [];
        foreach ($signals as $signal) {
            if (! $signal['substantial'] || $signal['language'] === null) {
                continue;
            }
            if ($signal['opening_assistant'] && $this->hasLaterSubstantial($signals)) {
                continue;
            }
            $substantial[] = $signal['language'];
        }

        if ($substantial === []) {
            return null;
        }

        $lastThree = array_slice($substantial, -3);
        if (count($lastThree) >= 3 && count(array_unique($lastThree)) === 1) {
            return $lastThree[0];
        }

        $window = array_slice($substantial, -self::LAST_WINDOW);
        $counts = array_count_values($window);
        arsort($counts);
        $top = array_key_first($counts);
        $topCount = $counts[$top] ?? 0;

        if ($top === null || ! in_array($top, VoiceSupportedLanguage::SUPPORTED, true)) {
            return null;
        }

        if (count($window) === 1) {
            return $top;
        }

        if ($topCount >= (int) ceil(count($window) * 0.75)) {
            return $top;
        }

        $last = $window[array_key_last($window)];

        return in_array($last, VoiceSupportedLanguage::SUPPORTED, true) ? $last : null;
    }

    /**
     * @param  list<array{substantial: bool, opening_assistant: bool}>  $signals
     */
    private function hasLaterSubstantial(array $signals): bool
    {
        foreach ($signals as $index => $signal) {
            if ($index === 0) {
                continue;
            }
            if ($signal['substantial']) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $codes
     */
    private function lastTagLanguage(array $codes): ?string
    {
        if ($codes === []) {
            return null;
        }

        $last = $codes[array_key_last($codes)];

        return VoiceSupportedLanguage::tryNormalize($last);
    }

    private function detectScriptLanguage(string $text): ?string
    {
        $letters = preg_replace('/[^\p{L}]+/u', '', $text) ?? '';
        if ($letters === '') {
            return null;
        }

        $cyrillic = preg_match_all('/\p{Cyrillic}/u', $letters) ?: 0;
        $latin = preg_match_all('/\p{Latin}/u', $letters) ?: 0;
        $total = $cyrillic + $latin;
        if ($total === 0) {
            return null;
        }

        if ($cyrillic * 2 >= $total) {
            return $this->resolveCyrillic($text);
        }

        if ($latin * 2 >= $total) {
            return 'en';
        }

        return null;
    }

    private function resolveCyrillic(string $text): string
    {
        if (preg_match('/[іїєґІЇЄҐ]/u', $text) === 1) {
            return 'uk';
        }

        if (preg_match('/[ыэъёЫЭЪЁ]/u', $text) === 1) {
            return 'ru';
        }

        $normalized = mb_strtolower($text);
        $uk = $this->countMarkers($normalized, self::UK_MARKERS);
        $ru = $this->countMarkers($normalized, self::RU_MARKERS);

        if ($uk > $ru) {
            return 'uk';
        }

        if ($ru > $uk) {
            return 'ru';
        }

        return 'ru';
    }

    /**
     * @param  list<string>  $markers
     */
    private function countMarkers(string $normalized, array $markers): int
    {
        $hits = 0;
        foreach ($markers as $marker) {
            $quoted = preg_quote($marker, '/');
            if (preg_match('/(?:^|[^\p{L}\p{N}])'.$quoted.'/u', $normalized) === 1) {
                $hits++;
            }
        }

        return $hits;
    }

    private function letterCount(string $text): int
    {
        return preg_match_all('/\p{L}/u', $text) ?: 0;
    }
}
