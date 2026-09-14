<?php

namespace Tests\Unit;

use App\Support\InstagramOutboundLinkButtons;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class OutboundLinkThrottleTest extends TestCase
{
    #[Test]
    public function already_sent_links_do_not_become_buttons_again(): void
    {
        $reply = "Посмотрите наши шоу на сайте https://www.youngfashionshow.com/ и на YouTube https://www.youtube.com/@YoungFashionShow";

        $bundle = InstagramOutboundLinkButtons::fromReply($reply, 'ru', [], true, ['website', 'youtube']);

        $this->assertSame([], $bundle['cards']);
        $this->assertStringNotContainsString('http', $bundle['plain']);
        $this->assertStringContainsString('Посмотрите наши шоу', $bundle['plain']);
    }

    #[Test]
    public function the_application_form_is_never_throttled(): void
    {
        $reply = "Заявка: https://form.youngfashionshow.com/crm_form_ppigu/\nYouTube: https://www.youtube.com/@YoungFashionShow";

        $bundle = InstagramOutboundLinkButtons::fromReply($reply, 'ru', [], true, ['youtube', 'form']);

        $this->assertCount(1, $bundle['cards']);
        $this->assertSame(
            ['https://form.youngfashionshow.com/crm_form_ppigu/'],
            array_column($bundle['cards'][0]['buttons'], 'url'),
        );
    }

    #[Test]
    public function a_first_time_youtube_mention_still_produces_a_button(): void
    {
        $reply = 'Полные записи наших шоу есть на YouTube.';

        $bundle = InstagramOutboundLinkButtons::fromReply($reply, 'ru', [], true);

        $this->assertCount(1, $bundle['cards']);
        $this->assertSame(
            [InstagramOutboundLinkButtons::DEFAULT_YOUTUBE_URL],
            array_column($bundle['cards'][0]['buttons'], 'url'),
        );
    }

    #[Test]
    public function a_repeated_youtube_mention_produces_no_button(): void
    {
        $reply = 'Полные записи наших шоу есть на YouTube.';

        $bundle = InstagramOutboundLinkButtons::fromReply($reply, 'ru', [], true, ['youtube']);

        $this->assertSame([], $bundle['cards']);
    }
}
