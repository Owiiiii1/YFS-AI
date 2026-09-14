<?php

namespace App\Services\Instagram;

use App\Models\Conversation;
use App\Models\ConversationMessage;

class ConversationLanguageResolver
{
    /** Distinctive Ukrainian tokens (work even without і/ї/є/ґ). */
    private const UK_WORDS = [
        'дякую', 'дякуючи', 'будь ласка', 'будь-ласка', 'вітаю', 'привіт',
        'вибачте', 'вибач', 'можна', 'потрібно', 'треба', 'скільки', 'чому',
        'який', 'яка', 'яке', 'які', 'або', 'також', 'вже', 'дуже',
        'гарний', 'гарна', 'гарне', 'гарного', 'гарних', 'прошу',
        'добрий', 'доброго', 'добраніч', 'маю', 'маємо', 'маєш', 'має',
        'двох', 'дві', 'дитина', 'дитини', 'дітей', 'діток', 'дітки',
        'подати', 'подайте', 'заявк', 'хотіла б', 'після', 'поки',
        'скажіть', 'напишіть', 'підкаж', 'україн',
        'замовити', 'замовлення', 'замовленням', 'замовл', 'осіб', 'особа',
        'суботу', 'субота', 'середу', 'середа', 'неділя', 'неділю',
        'понеділок', 'вівторок', 'п\'ятниц', 'пʼятниц', 'якщо', 'зараз',
        'сьогодні', 'смаколик', 'напиши',
    ];

    /** Distinctive Russian tokens. */
    private const RU_WORDS = [
        'спасибо', 'пожалуйста', 'здравствуйте', 'сколько', 'можно',
        'нужно', 'почему', 'который', 'которая', 'которое', 'или',
        'уже', 'очень', 'суббота', 'субботу', 'сегодня', 'если', 'сейчас',
        'заказать', 'заказ', 'человек', 'хорошо', 'хороший', 'напишите',
        'привет', 'ещё', 'еще', 'добрый', 'ребёнок', 'ребенок', 'ребёнка',
        'ребенка', 'подать',
    ];

    /** Distinctive Romanian tokens (work even without diacritics). */
    /**
     * English tokens, scored alongside the other Latin-script languages so that
     * stems like "participa" do not steal plain English sentences.
     */
    private const EN_WORDS = [
        'hello', 'hi ', 'thanks', 'thank you', 'please', 'child', 'children', 'kid',
        'daughter', 'son', 'years old', 'age', 'how', 'what', 'when', 'where',
        'we are', 'we want', 'i want', 'my', 'the', 'and', 'you', 'your',
        'application', 'form', 'show', 'runway', 'would', 'could', 'about', 'with',
    ];

    private const RO_WORDS = [
        'bună', 'buna', 'copilul', 'copiii', 'potrivit', 'pentru', 'suntem',
        'avem', 'experien', 'român', 'romania', 'mulțumesc', 'multumesc',
        'înțeles', 'inteles', 'fetița', 'fetita', 'băiat', 'baiat',
        'vă rog', 'va rog', 'noastră', 'noastra', 'poate', 'scuze',
        'salut', 'desigur', 'participa', 'cerere', 'formular',
    ];

    /** Distinctive Spanish tokens. */
    private const ES_WORDS = [
        'hola', 'gracias', 'niño', 'ninos', 'niños', 'por favor',
        'cuánto', 'cuanto', 'nuestro', 'nuestra', 'solicitud',
        'pasarela', 'quiero participar', 'buenos días', 'buenos dias',
        'buenas', 'usted', 'ustedes', 'información', 'informacion',
    ];

    /** Distinctive German tokens. */
    private const DE_WORDS = [
        'hallo', 'könnte', 'koennte', 'tochter', 'deutschland', 'bewerben',
        'schade', 'grüße', 'gruesse', 'liebe grüße', 'würde', 'wuerde',
        'wirklich', 'schwierig', 'bisschen', 'vielleicht', 'wahrscheinlich',
        'warscheinlich', 'unsere', 'meine tochter', 'anmelden', 'als model',
    ];

    /** Distinctive French tokens. */
    private const FR_WORDS = [
        'bonjour', 'merci', 'fille', 's’il vous plaît', 'sil vous plait',
        'comment', 'inscrire', 'participer', 'notre fille',
    ];

    /** Distinctive Italian tokens. */
    private const IT_WORDS = [
        'ciao', 'grazie', 'figlia', 'vorrei', 'iscrivere', 'partecipare',
    ];

    /** Distinctive Polish tokens. */
    private const PL_WORDS = [
        'dzień dobry', 'dzien dobry', 'córka', 'corka', 'proszę', 'prosze',
        'dziękuję', 'dziekuje', 'cześć', 'czesc',
    ];

    public function resolveFromConversation(Conversation $conversation, ?string $latestMessage = null): string
    {
        $samples = [];

        if (filled($latestMessage) && ! $this->isPlaceholder((string) $latestMessage)) {
            $samples[] = (string) $latestMessage;
        }

        if ($conversation->exists) {
            $conversation->messages()
                ->where('direction', ConversationMessage::DIRECTION_INBOUND)
                ->where('sender_type', ConversationMessage::SENDER_CUSTOMER)
                ->orderByDesc('sent_at')
                ->orderByDesc('id')
                ->limit(12)
                ->pluck('body')
                ->each(function (mixed $body) use (&$samples): void {
                    $text = trim((string) $body);
                    if ($text !== '' && ! $this->isPlaceholder($text)) {
                        $samples[] = $text;
                    }
                });
        }

        foreach ($samples as $sample) {
            if ($this->isWeakSignal($sample)) {
                continue;
            }

            return $this->resolve($sample);
        }

        return $this->resolve(implode("\n", $samples));
    }

    public function resolve(string $text): string
    {
        $text = trim($text);

        if ($text === '' || $this->isPlaceholder($text)) {
            return 'en';
        }

        if (preg_match('/\p{Armenian}/u', $text)) {
            return 'hy';
        }

        if (preg_match('/[äöüßÄÖÜ]/u', $text)) {
            return 'de';
        }

        if (preg_match('/[ąęćłńóśźżĄĘĆŁŃÓŚŹŻ]/u', $text)) {
            return 'pl';
        }

        if (preg_match('/[ăâîșşțţĂÂÎȘŞȚŢ]/u', $text)) {
            return 'ro';
        }

        if (preg_match('/[ñÑ¿¡]/u', $text)) {
            return 'es';
        }

        if (preg_match('/[çœÇŒ]/u', $text)) {
            return 'fr';
        }

        if ($this->hasCyrillic($text)) {
            if (preg_match('/[іїєґІЇЄҐ]/u', $text)) {
                return 'uk';
            }

            if (preg_match('/[ыэъёЫЭЪЁ]/u', $text)) {
                return 'ru';
            }

            $normalized = mb_strtolower($text);
            $ukScore = $this->countHits($normalized, self::UK_WORDS);
            $ruScore = $this->countHits($normalized, self::RU_WORDS);

            if ($ukScore > $ruScore) {
                return 'uk';
            }

            if ($ruScore > $ukScore) {
                return 'ru';
            }

            return 'ru';
        }

        $normalized = mb_strtolower($text);
        $scores = [
            'en' => $this->countHits($normalized, self::EN_WORDS),
            'de' => $this->countHits($normalized, self::DE_WORDS),
            'fr' => $this->countHits($normalized, self::FR_WORDS),
            'it' => $this->countHits($normalized, self::IT_WORDS),
            'pl' => $this->countHits($normalized, self::PL_WORDS),
            'ro' => $this->countHits($normalized, self::RO_WORDS),
            'es' => $this->countHits($normalized, self::ES_WORDS),
        ];
        arsort($scores);
        $best = array_key_first($scores);
        if ($best !== null && $scores[$best] > 0) {
            return $best;
        }

        if ($this->looksNonEnglish($text)) {
            return 'other';
        }

        return 'en';
    }

    public function languageName(string $code): string
    {
        return match ($code) {
            'uk' => 'Ukrainian',
            'ru' => 'Russian',
            'ro' => 'Romanian',
            'es' => 'Spanish',
            'hy' => 'Armenian',
            'de' => 'German',
            'fr' => 'French',
            'it' => 'Italian',
            'pl' => 'Polish',
            'other' => "the same language the customer is writing — do not use English unless they wrote in English",
            default => 'English',
        };
    }

    public function isWeakSignal(string $text): bool
    {
        $normalized = mb_strtolower(trim($text));
        $normalized = preg_replace('/[^\p{L}\p{N}\s]+/u', '', $normalized) ?? $normalized;
        $normalized = trim(preg_replace('/\s+/u', ' ', $normalized) ?? $normalized);

        if ($normalized === '' || $this->isPlaceholder($text)) {
            return true;
        }

        if (preg_match('/[іїєґІЇЄҐыэъёЫЭЪЁăâîșşțţĂÂÎȘŞȚŢñÑ¿¡äöüßÄÖÜąęćłńóśźżĄĘĆŁŃÓŚŹŻçœÇŒ]/u', $text) || preg_match('/\p{Armenian}/u', $text)) {
            return false;
        }

        if ($this->countHits($normalized, self::UK_WORDS) > 0
            || $this->countHits($normalized, self::RU_WORDS) > 0
            || $this->countHits($normalized, self::RO_WORDS) > 0
            || $this->countHits($normalized, self::ES_WORDS) > 0
            || $this->countHits($normalized, self::DE_WORDS) > 0
            || $this->countHits($normalized, self::FR_WORDS) > 0
            || $this->countHits($normalized, self::IT_WORDS) > 0
            || $this->countHits($normalized, self::PL_WORDS) > 0) {
            return false;
        }

        return mb_strlen($normalized) <= 12;
    }

    private function looksNonEnglish(string $text): bool
    {
        return (bool) preg_match('/\p{L}/u', $text)
            && (bool) preg_match('/[^\x00-\x7Fa-zA-Z]/u', preg_replace('/\s+/u', '', $text) ?? $text)
            && (bool) preg_match('/\p{L}/u', preg_replace('/[\x00-\x7F]/u', '', $text) ?? '');
    }

    private function isPlaceholder(string $text): bool
    {
        $trimmed = trim($text);

        return $trimmed === ''
            || str_starts_with($trimmed, '[Instagram')
            || str_starts_with($trimmed, '[Facebook')
            || str_starts_with($trimmed, '[attachment');
    }

    private function hasCyrillic(string $text): bool
    {
        return (bool) preg_match('/\p{Cyrillic}/u', $text);
    }

    /**
     * @param  list<string>  $needles
     */
    private function countHits(string $normalized, array $needles): int
    {
        $hits = 0;

        foreach ($needles as $needle) {
            $quoted = preg_quote($needle, '/');
            if (preg_match('/(?:^|[^\p{L}\p{N}])'.$quoted.'/u', $normalized) === 1) {
                $hits++;
            }
        }

        return $hits;
    }
}
