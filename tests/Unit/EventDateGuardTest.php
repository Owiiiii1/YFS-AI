<?php

namespace Tests\Unit;

use App\Models\Conversation;
use App\Services\Bot\EventDateGuard;
use App\Services\Instagram\ConversationLanguageResolver;
use App\Services\Jfs\JfsReadService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class EventDateGuardTest extends TestCase
{
    private EventDateGuard $guard;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guard = new EventDateGuard(new JfsReadService(), new ConversationLanguageResolver());
        $this->conversation = new Conversation();
        $this->conversation->channel = 'instagram';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function events(): array
    {
        return [
            [
                'name' => 'YFS MIAMI',
                'city' => 'Miami',
                'location' => 'Miami',
                'starts_at' => '2026-04-26 00:00:00',
                'ends_at' => null,
                'date_announced' => true,
                'is_past' => true,
                'description' => null,
            ],
            [
                'name' => 'YFS CHICAGO',
                'city' => 'Chicago',
                'location' => 'Illinois',
                'starts_at' => null,
                'ends_at' => null,
                'date_announced' => false,
                'is_past' => false,
                'description' => null,
            ],
            [
                'name' => 'YFS NEW YORK',
                'city' => 'New York',
                'location' => 'NY',
                'starts_at' => '2027-03-07 00:00:00',
                'ends_at' => null,
                'date_announced' => true,
                'is_past' => false,
                'description' => null,
            ],
        ];
    }

    #[Test]
    public function a_past_date_is_removed_from_the_reply(): void
    {
        $reply = "Ближайшие показы:\n— Майами: 26 апреля 2026 года\nПодскажите, какой город вам удобнее?";

        $clean = $this->guard->sanitize($reply, $this->conversation, 'когда шоу?', $this->events());

        $this->assertStringNotContainsString('26 апреля 2026', $clean);
        $this->assertStringNotContainsString('Ближайшие показы:', $clean);
        $this->assertStringContainsString('какой город вам удобнее', $clean);
    }

    #[Test]
    public function a_hidden_date_is_removed_and_reported_as_pending(): void
    {
        $reply = 'Чикаго: 6 декабря 2026 года.';

        $clean = $this->guard->sanitize($reply, $this->conversation, 'когда шоу в чикаго?', $this->events());

        $this->assertStringNotContainsString('6 декабря 2026', $clean);
        $this->assertStringContainsString('Чикаго', $clean);
        $this->assertStringContainsString('на стадии подтверждения', $clean);
        $this->assertStringContainsString('анонсированы в ближайшее время', $clean);
    }

    #[Test]
    public function a_confirmed_upcoming_date_is_kept_untouched(): void
    {
        $reply = "Нью-Йорк: 7 марта 2027 года.\nБудем рады видеть вас!";

        $clean = $this->guard->sanitize($reply, $this->conversation, 'когда шоу?', $this->events());

        $this->assertSame($reply, $clean);
    }

    #[Test]
    public function a_reply_without_dates_is_untouched(): void
    {
        $reply = 'Наши шоу проходят в Майами, Чикаго и Нью-Йорке. Ребёнку 5 лет — отличный возраст!';

        $clean = $this->guard->sanitize($reply, $this->conversation, 'расскажите о шоу', $this->events());

        $this->assertSame($reply, $clean);
    }

    #[Test]
    public function a_month_without_a_day_is_also_checked(): void
    {
        $reply = 'Показ в Чикаго пройдёт в декабре 2026 года.';

        $clean = $this->guard->sanitize($reply, $this->conversation, 'когда шоу?', $this->events());

        $this->assertStringNotContainsString('декабре 2026', $clean);
    }

    #[Test]
    public function english_dates_are_checked_too(): void
    {
        $reply = "Our next shows:\n- Miami: April 26, 2026\n- New York: March 7, 2027";

        $clean = $this->guard->sanitize($reply, $this->conversation, 'when are the shows?', $this->events());

        $this->assertStringNotContainsString('April 26', $clean);
        $this->assertStringContainsString('March 7, 2027', $clean);
    }
}
