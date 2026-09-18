<?php

namespace Tests\Feature;

use App\Models\VoiceContact;
use App\Models\VoiceFollowup;
use App\Services\Telegram\TelegramBotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class ElevenLabsRequestHumanFollowupTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $telegramMessages = [];

    private bool $telegramFails = false;

    protected function setUp(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite is required for isolated feature tests.');
        }

        parent::setUp();

        config(['services.elevenlabs.tool_token' => 'test-elevenlabs-tool-token']);
        config(['services.voice_runtime.internal_token' => 'test-voice-runtime-token']);

        $this->app->instance(TelegramBotService::class, new class($this) extends TelegramBotService
        {
            public function __construct(private ElevenLabsRequestHumanFollowupTest $test) {}

            public function sendChannelText(string $text): void
            {
                if ($this->test->telegramShouldFail()) {
                    throw new RuntimeException('telegram unavailable');
                }
                $this->test->recordTelegram($text);
            }
        });
    }

    public function recordTelegram(string $text): void
    {
        $this->telegramMessages[] = $text;
    }

    public function telegramShouldFail(): bool
    {
        return $this->telegramFails;
    }

    #[Test]
    public function auth_is_required(): void
    {
        $this->postJson('/api/voice/tools/request-human-followup')->assertUnauthorized();
        $this->postJson('/api/voice/tools/request-human-followup', [], [
            'Authorization' => 'Bearer wrong-token',
        ])->assertUnauthorized();
    }

    #[Test]
    public function unknown_sales_lead_creates_followup_without_yfs_identity(): void
    {
        $response = $this->postJson('/api/voice/tools/request-human-followup', [
            'system__conversation_id' => 'conv_unknown_lead',
            'system__caller_id' => '+15550001111',
            'department' => 'sales',
            'reason' => 'Caller asked Sales to call back about a child application.',
            'callback_requested' => true,
            'callback_phone' => '+15558887777',
            'preferred_callback_time' => 'any time during the day',
            'customer_name' => 'Alex Parent',
            'child_name' => 'Mia',
            'show_city' => 'Miami',
            'summary' => 'Application could not be uniquely identified. Caller wants a Sales callback.',
            'customer_id' => 999,
            'app_user_id' => 13,
            'voice_contact_id' => 4,
            'bitrix_id' => 'crm:1',
        ], $this->auth());

        $response->assertOk()
            ->assertExactJson([
                'ok' => true,
                'status' => 'created',
                'department' => 'sales',
            ])
            ->assertDontSee('999', false)
            ->assertDontSee('conv_unknown_lead', false)
            ->assertDontSee('test-elevenlabs-tool-token', false);

        $this->assertSame(1, VoiceFollowup::query()->count());
        $followup = VoiceFollowup::query()->first();
        $this->assertSame('sales', $followup->department);
        $this->assertTrue($followup->callback_requested);
        $this->assertSame('+15558887777', $followup->callback_phone);
        $this->assertSame('any time during the day', $followup->preferred_callback_time);
        $this->assertSame('Alex Parent', $followup->customer_name);
        $this->assertSame('Mia', $followup->child_name);
        $this->assertSame('open', $followup->status);
        $this->assertSame('voice', $followup->source);
        $this->assertNotNull($followup->telegram_sent_at);
        $this->assertSame(VoiceFollowup::CREATED_BY_LIVE_TOOL, $followup->createdBy());
        $this->assertNull($followup->voice_call_id);
        $this->assertSame(1, VoiceContact::query()->count());
        $this->assertNull(data_get($followup->contact->metadata, 'yfs_customer'));

        $this->assertCount(1, $this->telegramMessages);
        $message = $this->telegramMessages[0];
        $this->assertStringContainsString('📞 Voice · Callback requested', $message);
        $this->assertStringContainsString('Department: Sales', $message);
        $this->assertStringContainsString('Customer: Alex Parent', $message);
        $this->assertStringContainsString('Child: Mia', $message);
        $this->assertStringContainsString('Show: Miami', $message);
        $this->assertStringContainsString('Callback: +15558887777', $message);
        $this->assertStringContainsString('Preferred time: any time during the day', $message);
        $this->assertStringContainsString('Caller asked Sales to call back', $message);
        $this->assertStringNotContainsString('999', $message);
        $this->assertStringNotContainsString('app_user_id', $message);
        $this->assertStringNotContainsString('conv_unknown_lead', $message);
        $this->assertStringNotContainsString('crm:1', $message);
    }

    #[Test]
    public function duplicate_tool_call_is_idempotent_and_does_not_resend_telegram(): void
    {
        $payload = [
            'system__conversation_id' => 'conv_dup',
            'system__caller_id' => '+15550002222',
            'department' => 'sales',
            'reason' => 'Callback please',
            'callback_requested' => true,
            'callback_phone' => '+15550002222',
        ];

        $this->postJson('/api/voice/tools/request-human-followup', $payload, $this->auth())
            ->assertOk()
            ->assertJsonPath('status', 'created');
        $this->postJson('/api/voice/tools/request-human-followup', $payload, $this->auth())
            ->assertOk()
            ->assertExactJson([
                'ok' => true,
                'status' => 'already_created',
                'department' => 'sales',
            ]);

        $this->assertSame(1, VoiceFollowup::query()->count());
        $this->assertCount(1, $this->telegramMessages);
    }

    #[Test]
    public function telegram_failure_keeps_followup_and_does_not_confirm_delivery(): void
    {
        $this->telegramFails = true;

        $this->postJson('/api/voice/tools/request-human-followup', [
            'system__conversation_id' => 'conv_queued',
            'system__caller_id' => '+15550003333',
            'department' => 'sales',
            'reason' => 'Need a callback',
            'callback_requested' => true,
            'callback_phone' => '+15550003333',
        ], $this->auth())
            ->assertOk()
            ->assertExactJson([
                'ok' => false,
                'status' => 'queued',
                'department' => 'sales',
            ]);

        $followup = VoiceFollowup::query()->first();
        $this->assertNotNull($followup);
        $this->assertNull($followup->telegram_sent_at);
        $this->assertSame([], $this->telegramMessages);
    }

    #[Test]
    public function dictated_callback_phone_is_used_instead_of_caller_id(): void
    {
        $this->postJson('/api/voice/tools/request-human-followup', [
            'system__conversation_id' => 'conv_other_phone',
            'system__caller_id' => '+15550004444',
            'department' => 'sales',
            'reason' => 'Call this other number',
            'callback_requested' => true,
            'callback_phone' => '+15551112222',
        ], $this->auth())->assertOk();

        $this->assertSame('+15551112222', VoiceFollowup::query()->value('callback_phone'));
    }

    #[Test]
    public function missing_conversation_returns_failed_without_ids(): void
    {
        $this->postJson('/api/voice/tools/request-human-followup', [
            'department' => 'sales',
            'reason' => 'Callback',
            'callback_requested' => true,
        ], $this->auth())
            ->assertOk()
            ->assertExactJson([
                'ok' => false,
                'status' => 'failed',
            ]);

        $this->assertSame(0, VoiceFollowup::query()->count());
        $this->assertSame([], $this->telegramMessages);
    }

    /**
     * @return array<string, string>
     */
    private function auth(): array
    {
        return ['Authorization' => 'Bearer test-elevenlabs-tool-token'];
    }
}
