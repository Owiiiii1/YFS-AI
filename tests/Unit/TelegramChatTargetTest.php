<?php

namespace Tests\Unit;

use App\Support\TelegramChatTarget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class TelegramChatTargetTest extends TestCase
{
    #[Test]
    public function it_keeps_a_plain_username(): void
    {
        $target = TelegramChatTarget::parse('@youngfashionshow');

        $this->assertSame('@youngfashionshow', $target->chat);
        $this->assertNull($target->threadId);
    }

    #[Test]
    public function it_parses_a_private_forum_message_link(): void
    {
        $target = TelegramChatTarget::parse('https://t.me/c/1234567890/42/100');

        $this->assertSame('-1001234567890', $target->chat);
        $this->assertSame(42, $target->threadId);
    }

    #[Test]
    public function it_parses_a_public_topic_link(): void
    {
        $target = TelegramChatTarget::parse('https://t.me/company_group/15');

        $this->assertSame('@company_group', $target->chat);
        $this->assertSame(15, $target->threadId);
    }

    #[Test]
    public function it_lets_an_explicit_topic_override_the_link(): void
    {
        $target = TelegramChatTarget::parse('https://t.me/c/1234567890/42', '88');

        $this->assertSame('-1001234567890', $target->chat);
        $this->assertSame(88, $target->threadId);
    }

    #[Test]
    public function it_reads_a_topic_from_a_message_link_in_the_topic_field(): void
    {
        $target = TelegramChatTarget::parse('-1001234567890', 'https://t.me/c/1234567890/7/22');

        $this->assertSame('-1001234567890', $target->chat);
        $this->assertSame(7, $target->threadId);
    }

    #[Test]
    public function it_rejects_a_blank_chat(): void
    {
        $this->expectException(RuntimeException::class);

        TelegramChatTarget::parse('   ');
    }
}
