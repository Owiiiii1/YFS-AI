<?php

namespace Tests\Unit\Voice;

use App\Support\VoiceSupportedLanguage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VoiceSupportedLanguageTest extends TestCase
{
    #[Test]
    public function it_normalizes_supported_language_tags(): void
    {
        $this->assertSame('en', VoiceSupportedLanguage::tryNormalize('en-US'));
        $this->assertSame('ru', VoiceSupportedLanguage::tryNormalize('ru_RU'));
        $this->assertSame('uk', VoiceSupportedLanguage::tryNormalize('uk-UA'));
        $this->assertSame('uk', VoiceSupportedLanguage::tryNormalize('ua'));
    }

    #[Test]
    public function unknown_values_are_rejected(): void
    {
        $this->assertNull(VoiceSupportedLanguage::tryNormalize(null));
        $this->assertNull(VoiceSupportedLanguage::tryNormalize(''));
        $this->assertNull(VoiceSupportedLanguage::tryNormalize('fr'));
        $this->assertNull(VoiceSupportedLanguage::tryNormalize('unknown'));
        $this->assertFalse(VoiceSupportedLanguage::isSupported('xx'));
    }
}
