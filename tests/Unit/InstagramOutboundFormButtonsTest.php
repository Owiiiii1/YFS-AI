<?php

namespace Tests\Unit;

use App\Support\InstagramOutboundFormButtons;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InstagramOutboundFormButtonsTest extends TestCase
{
    #[Test]
    public function it_extracts_and_strips_official_form_urls(): void
    {
        $text = "Здравствуйте! Спасибо за предложение.\n"
            ."Форма: https://form.youngfashionshow.com/crm_form_e5l3g/\n"
            .'Команда рассмотрит заявку.';

        $urls = InstagramOutboundFormButtons::urlsIn($text);

        $this->assertSame(['https://form.youngfashionshow.com/crm_form_e5l3g/'], $urls);

        $stripped = InstagramOutboundFormButtons::strip($text, $urls);

        $this->assertStringNotContainsString('form.youngfashionshow.com', $stripped);
        $this->assertStringContainsString('Спасибо за предложение', $stripped);
        $this->assertStringContainsString('Команда рассмотрит заявку', $stripped);
    }

    #[Test]
    public function russian_button_copy_fits_instagram_limits(): void
    {
        $copy = InstagramOutboundFormButtons::copy('ru', [
            'labels' => [
                'en' => 'Team / specialist / partner',
                'ru' => 'Команда / специалист / партнёр',
                'uk' => 'Команда / спеціаліст / партнер',
            ],
        ]);

        $this->assertLessThanOrEqual(20, mb_strlen($copy['button']));
        $this->assertLessThanOrEqual(80, mb_strlen($copy['title']));
        $this->assertLessThanOrEqual(80, mb_strlen($copy['subtitle']));
        $this->assertSame('Открыть форму', $copy['button']);
    }
}
