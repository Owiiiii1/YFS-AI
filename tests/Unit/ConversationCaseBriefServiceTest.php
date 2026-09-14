<?php

namespace Tests\Unit;

use App\Models\BotReply;
use App\Services\Bot\ConversationCaseBriefService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ConversationCaseBriefServiceTest extends TestCase
{
    #[Test]
    public function fallback_does_not_repeat_a_bare_yes(): void
    {
        $service = app(ConversationCaseBriefService::class);

        $brief = $service->fallback(
            BotReply::TYPE_OPERATOR_NEEDED,
            "Клиент: сколько стоит участие\nБот: подключить менеджера?\nКлиент: да",
            'да',
        );

        $this->assertStringNotContainsStringIgnoringCase('Bot sent', $brief);
        $this->assertStringContainsString('оператор', mb_strtolower($brief));
        $this->assertStringContainsString('стоимость', mb_strtolower($brief));
    }
}
