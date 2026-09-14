<?php

namespace Tests\Unit;

use App\Support\InstagramOutboundLinkButtons;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InstagramOutboundLinkButtonsTest extends TestCase
{
    #[Test]
    public function youtube_only_reply_adds_instagram_and_strips_raw_urls(): void
    {
        $text = "Полные записи шоу — на YouTube:\nhttps://www.youtube.com/@YoungFashionShow\n"
            .'Короткое видео и stories — в нашем Instagram.';

        $bundle = InstagramOutboundLinkButtons::fromReply($text);

        $this->assertStringNotContainsString('https://', $bundle['plain']);
        $this->assertStringContainsString('Полные записи шоу', $bundle['plain']);
        $this->assertCount(1, $bundle['cards']);
        $this->assertCount(2, $bundle['cards'][0]['buttons']);
        $this->assertSame('Instagram', $bundle['cards'][0]['buttons'][0]['title']);
        $this->assertSame('YouTube', $bundle['cards'][0]['buttons'][1]['title']);
        $this->assertSame(
            'https://www.instagram.com/young.fashion.show/',
            $bundle['cards'][0]['buttons'][0]['url'],
        );
        $this->assertSame(
            'https://www.youtube.com/@YoungFashionShow',
            $bundle['cards'][0]['buttons'][1]['url'],
        );
    }

    #[Test]
    public function parent_intro_with_photo_video_does_not_attach_social_buttons(): void
    {
        $text = 'Это fashion show для детей: подготовка и профессиональные фото/видео.'
            ."\n\nХотите подать заявку на участие ребёнка?";

        $bundle = InstagramOutboundLinkButtons::fromReply($text);

        $this->assertSame($text, $bundle['plain']);
        $this->assertSame([], $bundle['cards']);
    }

    #[Test]
    public function instagram_direct_does_not_attach_an_instagram_profile_button(): void
    {
        $text = "Побачити образи можна тут:\nhttps://www.instagram.com/young.fashion.show/\n"
            .'Бажаєте відео з минулих показів?';

        $bundle = InstagramOutboundLinkButtons::fromReply($text, 'uk', [], true);

        $this->assertStringNotContainsString('instagram.com', $bundle['plain']);
        foreach ($bundle['cards'] as $card) {
            foreach ($card['buttons'] as $button) {
                $this->assertStringNotContainsString('instagram.com', (string) $button['url']);
            }
        }
    }

    #[Test]
    public function form_url_stays_a_form_button_not_a_video_card(): void
    {
        $text = "Отлично! Заполните заявку здесь:\nhttps://form.youngfashionshow.com/crm_form_ppigu/";

        $bundle = InstagramOutboundLinkButtons::fromReply($text);

        $this->assertStringNotContainsString('form.youngfashionshow.com', $bundle['plain']);
        $this->assertCount(1, $bundle['cards']);
        $this->assertSame('Открыть форму', $bundle['cards'][0]['buttons'][0]['title']);
        $this->assertCount(1, $bundle['cards'][0]['buttons']);
    }
}
