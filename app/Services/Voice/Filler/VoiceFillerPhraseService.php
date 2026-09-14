<?php

namespace App\Services\Voice\Filler;

final class VoiceFillerPhraseService
{
    /**
     * ElevenLabs Custom LLM buffer words must end with ellipsis + space
     * so TTS can continue the same SSE stream without joining tokens onto "...".
     *
     * @see https://elevenlabs.io/docs/eleven-agents/customization/llm/custom-llm
     *
     * @var array<string, array<string, list<array{id: string, text: string}>>>
     */
    private const PHRASES = [
        'en' => [
            'lookup' => [
                ['id' => 'en_lookup_1', 'text' => 'One moment, let me check... '],
                ['id' => 'en_lookup_2', 'text' => "Give me a second, I'll look that up... "],
                ['id' => 'en_lookup_3', 'text' => 'Let me verify that for you... '],
            ],
            'search' => [
                ['id' => 'en_search_1', 'text' => 'One moment, I will search... '],
                ['id' => 'en_search_2', 'text' => 'Give me a second, I am looking... '],
            ],
            'crm' => [
                ['id' => 'en_crm_1', 'text' => 'One moment, I will check the record... '],
                ['id' => 'en_crm_2', 'text' => 'Give me a second, looking that up... '],
            ],
            'action' => [
                ['id' => 'en_action_1', 'text' => 'Okay, I will take care of that... '],
                ['id' => 'en_action_2', 'text' => 'Sure, I am doing that now... '],
            ],
        ],
        'ru' => [
            'lookup' => [
                ['id' => 'ru_lookup_1', 'text' => 'Секунду, сейчас проверю... '],
                ['id' => 'ru_lookup_2', 'text' => 'Минутку, я посмотрю... '],
                ['id' => 'ru_lookup_3', 'text' => 'Сейчас уточню... '],
            ],
            'search' => [
                ['id' => 'ru_search_1', 'text' => 'Минутку, я поищу... '],
                ['id' => 'ru_search_2', 'text' => 'Секунду, сейчас найду... '],
            ],
            'crm' => [
                ['id' => 'ru_crm_1', 'text' => 'Секунду, посмотрю информацию... '],
                ['id' => 'ru_crm_2', 'text' => 'Минутку, уточню по данным... '],
            ],
            'action' => [
                ['id' => 'ru_action_1', 'text' => 'Хорошо, сейчас оформлю... '],
                ['id' => 'ru_action_2', 'text' => 'Секунду, сделаю это... '],
            ],
        ],
        'uk' => [
            'lookup' => [
                ['id' => 'uk_lookup_1', 'text' => 'Секунду, зараз перевірю... '],
                ['id' => 'uk_lookup_2', 'text' => 'Хвилинку, я подивлюся... '],
                ['id' => 'uk_lookup_3', 'text' => 'Зараз уточню... '],
            ],
            'search' => [
                ['id' => 'uk_search_1', 'text' => 'Хвилинку, я пошукаю... '],
                ['id' => 'uk_search_2', 'text' => 'Секунду, зараз знайду... '],
            ],
            'crm' => [
                ['id' => 'uk_crm_1', 'text' => 'Секунду, подивлюся інформацію... '],
                ['id' => 'uk_crm_2', 'text' => 'Хвилинку, уточню за даними... '],
            ],
            'action' => [
                ['id' => 'uk_action_1', 'text' => 'Добре, зараз оформлю... '],
                ['id' => 'uk_action_2', 'text' => 'Секунду, зроблю це... '],
            ],
        ],
    ];

    public function enabled(): bool
    {
        return (bool) config('services.voice_runtime.filler_enabled', true);
    }

    public function select(string $language, string $category, string $seed = ''): VoiceFillerPhrase
    {
        $language = in_array($language, ['en', 'ru', 'uk'], true) ? $language : 'en';
        $category = in_array($category, ['lookup', 'search', 'crm', 'action'], true) ? $category : 'lookup';
        $options = self::PHRASES[$language][$category];
        $index = abs(crc32($seed.$language.$category)) % count($options);
        $chosen = $options[$index];

        return new VoiceFillerPhrase($chosen['id'], $language, $category, $chosen['text']);
    }
}
