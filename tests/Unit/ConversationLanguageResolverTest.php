<?php

namespace Tests\Unit;

use App\Services\Instagram\ConversationLanguageResolver;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ConversationLanguageResolverTest extends TestCase
{
    private ConversationLanguageResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new ConversationLanguageResolver;
    }

    #[Test]
    public function ukrainian_greeting_without_yi_is_ukrainian(): void
    {
        $this->assertSame('uk', $this->resolver->resolve('Добрий день'));
    }

    #[Test]
    public function russian_greeting_is_russian(): void
    {
        $this->assertSame('ru', $this->resolver->resolve('Добрый день'));
        $this->assertSame('ru', $this->resolver->resolve('Здравствуйте'));
    }

    #[Test]
    public function ukrainian_application_phrase_is_ukrainian(): void
    {
        $this->assertSame('uk', $this->resolver->resolve('Хочу подати заявку'));
        $this->assertSame('uk', $this->resolver->resolve('Маю двох дуже гарних діток'));
    }

    #[Test]
    public function short_yes_is_a_weak_signal(): void
    {
        $this->assertTrue($this->resolver->isWeakSignal('Так'));
        $this->assertTrue($this->resolver->isWeakSignal('да'));
        $this->assertFalse($this->resolver->isWeakSignal('Добрий день'));
        $this->assertFalse($this->resolver->isWeakSignal('Хочу подати заявку'));
    }

    #[Test]
    public function english_stays_english(): void
    {
        $this->assertSame('en', $this->resolver->resolve('How much does it cost?'));
    }

    #[Test]
    public function romanian_is_romanian(): void
    {
        $this->assertSame('ro', $this->resolver->resolve('Bună! copilul meu este potrivit pentru a fi model?'));
        $this->assertSame('ro', $this->resolver->resolve('Nu avem experiența .'));
        $this->assertSame('ro', $this->resolver->resolve('Suntem din România'));
        $this->assertSame('ro', $this->resolver->resolve('Suntem din Romania'));
        $this->assertFalse($this->resolver->isWeakSignal('Am înțeles ..'));
        $this->assertSame('Romanian', $this->resolver->languageName('ro'));
    }

    #[Test]
    public function german_is_german(): void
    {
        $this->assertSame('de', $this->resolver->resolve('Hallo könnte man sich bei Ihnen bewerben als Model ? Meine Tochter würde sich freuen'));
        $this->assertSame('de', $this->resolver->resolve('Wir kommen aus Deutschland'));
        $this->assertSame('de', $this->resolver->resolve('Sehr schade dann wird es warscheinlich wirklich ein bisschen schwierig'));
        $this->assertSame('German', $this->resolver->languageName('de'));
    }

    #[Test]
    public function spanish_and_armenian_are_detected(): void
    {
        $this->assertSame('es', $this->resolver->resolve('Hola, ¿cuánto cuesta participar?'));
        $this->assertSame('hy', $this->resolver->resolve('Բարեւ ձեզ'));
    }
}
