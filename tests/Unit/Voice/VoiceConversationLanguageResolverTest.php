<?php

namespace Tests\Unit\Voice;

use App\Services\Voice\Calls\VoiceTranscriptSanitizer;
use App\Services\Voice\Language\VoiceConversationLanguageResolver;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VoiceConversationLanguageResolverTest extends TestCase
{
    private VoiceConversationLanguageResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new VoiceConversationLanguageResolver(new VoiceTranscriptSanitizer);
    }

    #[Test]
    public function english_greeting_plus_sustained_russian_resolves_to_ru(): void
    {
        $this->assertSame('ru', $this->resolver->resolve($this->russianAfterEnglishGreeting(), [], 'en'));
    }

    #[Test]
    public function english_greeting_plus_sustained_ukrainian_resolves_to_uk(): void
    {
        $transcript = [
            ['role' => 'agent', 'message' => 'Hello, this is the Young Fashion Show AI assistant. How can I help you today?'],
            ['role' => 'user', 'message' => 'Добрий день. Підкажіть, будь ласка, як подати заявку на шоу.'],
            ['role' => 'agent', 'message' => '<Ukr>Звичайно, розкажу українською. Дитина може брати участь.</Ukr>'],
            ['role' => 'user', 'message' => 'Моїй дитині 15 років. Вона може брати участь?'],
            ['role' => 'agent', 'message' => '<Ukr>Так, за віком це можливо. Потрібна заявка в додатку.</Ukr>'],
        ];

        $this->assertSame('uk', $this->resolver->resolve($transcript, [], 'en'));
    }

    #[Test]
    public function entire_english_conversation_resolves_to_en(): void
    {
        $transcript = [
            ['role' => 'agent', 'message' => 'Hello, this is the Young Fashion Show AI assistant. How can I help you today?'],
            ['role' => 'user', 'message' => 'I want to register my daughter for the next show.'],
            ['role' => 'agent', 'message' => 'Sure, I can help with registration. How old is she?'],
            ['role' => 'user', 'message' => 'She is fifteen years old and we live in Miami.'],
        ];

        $this->assertSame('en', $this->resolver->resolve($transcript, [], 'uk'));
    }

    #[Test]
    public function russian_conversation_resolves_to_ru(): void
    {
        $transcript = [
            ['role' => 'agent', 'message' => '<Rus>Здравствуйте, я помощник Young Fashion Show. Чем могу помочь?</Rus>'],
            ['role' => 'user', 'message' => 'Подскажите, пожалуйста, почему запись на шоу уже закрыта.'],
            ['role' => 'agent', 'message' => '<Rus>Сейчас проверю правила записи и подскажу следующий шаг.</Rus>'],
        ];

        $this->assertSame('ru', $this->resolver->resolve($transcript, [], null));
    }

    #[Test]
    public function ukrainian_conversation_resolves_to_uk(): void
    {
        $transcript = [
            ['role' => 'agent', 'message' => '<Ukr>Добрий день, я асистент Young Fashion Show. Чим можу допомогти?</Ukr>'],
            ['role' => 'user', 'message' => 'Скажіть, будь ласка, як подати заявку на участь.'],
            ['role' => 'agent', 'message' => '<Ukr>Потрібно заповнити заявку в додатку. Я підкажу кроки.</Ukr>'],
        ];

        $this->assertSame('uk', $this->resolver->resolve($transcript, [], 'en'));
    }

    #[Test]
    public function one_isolated_foreign_phrase_does_not_switch_established_language(): void
    {
        $transcript = [
            ['role' => 'agent', 'message' => 'Hello, this is the Young Fashion Show AI assistant. How can I help you today?'],
            ['role' => 'user', 'message' => 'I want to register my daughter for the Miami show.'],
            ['role' => 'agent', 'message' => 'Of course. I can explain the application steps in the app.'],
            ['role' => 'user', 'message' => 'Спасибо'],
            ['role' => 'agent', 'message' => 'What is her age, and which city are you calling from?'],
            ['role' => 'user', 'message' => 'She is fifteen years old and we are in Miami.'],
        ];

        $this->assertSame('en', $this->resolver->resolve($transcript, [], 'ru'));
    }

    #[Test]
    public function later_sustained_language_switch_changes_resolved_language(): void
    {
        $transcript = [
            ['role' => 'agent', 'message' => 'Hello, this is the Young Fashion Show AI assistant. How can I help you today?'],
            ['role' => 'user', 'message' => 'I have a question about the application process.'],
            ['role' => 'agent', 'message' => 'Sure, I can help with registration details in the app.'],
            ['role' => 'user', 'message' => 'Подскажите, пожалуйста, почему запись на шоу уже закрыта.'],
            ['role' => 'agent', 'message' => '<Rus>Конечно, буду говорить по-русски. Сейчас поясню правила записи.</Rus>'],
            ['role' => 'user', 'message' => 'Моему ребёнку 15 лет. Она может участвовать?'],
            ['role' => 'agent', 'message' => '<Rus>Да, по возрасту это возможно. Нужна заявка в приложении.</Rus>'],
        ];

        $this->assertSame('ru', $this->resolver->resolve($transcript, [], 'en'));
    }

    #[Test]
    public function empty_or_ambiguous_transcript_falls_back_to_main_language(): void
    {
        $this->assertSame('uk', $this->resolver->resolve([], [], 'uk'));
        $this->assertSame('ru', $this->resolver->resolve([
            ['role' => 'agent', 'message' => 'Hi'],
            ['role' => 'user', 'message' => 'Ok'],
        ], [], 'ru'));
    }

    #[Test]
    public function unsupported_fallback_language_is_ignored(): void
    {
        $this->assertNull($this->resolver->resolve([], [], 'fr'));
        $this->assertNull($this->resolver->resolve([], [], 'unknown'));
        $this->assertNull($this->resolver->resolve([], [], null));
    }

    /**
     * @return list<array{role: string, message: string}>
     */
    private function russianAfterEnglishGreeting(): array
    {
        return [
            ['role' => 'agent', 'message' => 'Hello, this is the Young Fashion Show AI assistant. How can I help you today?'],
            ['role' => 'user', 'message' => 'Говори, что надо, русским языком.'],
            ['role' => 'agent', 'message' => '<Rus>Доброжелательно> Конечно, буду говорить по-русски.</Rus>'],
            ['role' => 'user', 'message' => 'Почему запись на шоу.'],
            ['role' => 'agent', 'message' => '<Rus>Сейчас поясню, как работает запись на шоу.</Rus>'],
            ['role' => 'user', 'message' => 'Моему ребёнку 15 лет. Она может участвовать?'],
            ['role' => 'agent', 'message' => '<Rus>Да, по возрасту это возможно. Нужна заявка в приложении.</Rus>'],
        ];
    }
}
