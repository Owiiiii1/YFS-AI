<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeVoiceCallJob;
use App\Models\VoiceCall;
use App\Models\VoiceCallAnalysis;
use App\Models\VoiceContact;
use App\Models\VoiceFollowup;
use App\Services\Telegram\TelegramBotService;
use App\Services\Voice\Calls\VoiceCallFollowupSafetyNet;
use App\Services\Voice\Calls\VoiceCallPostCallAnalysis;
use App\Services\Voice\Calls\VoiceCallPostCallAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeVoiceCallPostCallAnalyzer;
use Tests\TestCase;

class VoiceCallFollowupSafetyNetTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $telegramMessages = [];

    protected function setUp(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite is required for isolated feature tests.');
        }

        parent::setUp();

        config(['services.elevenlabs.tool_token' => 'test-elevenlabs-tool-token']);

        $this->app->instance(TelegramBotService::class, new class($this) extends TelegramBotService
        {
            public function __construct(private VoiceCallFollowupSafetyNetTest $test) {}

            public function sendChannelText(string $text): void
            {
                $this->test->recordTelegram($text);
            }
        });
    }

    public function recordTelegram(string $text): void
    {
        $this->telegramMessages[] = $text;
    }

    #[Test]
    public function live_followup_does_not_duplicate_row_or_telegram(): void
    {
        $call = $this->makeCall('conv_safety_a');
        $this->createLiveFollowup($call, 'conv_safety_a');
        $this->telegramMessages = [];

        $this->bindAnalyzer(new VoiceCallPostCallAnalysis(
            intent: 'sales_callback',
            department: 'sales',
            humanFollowupRequired: true,
            callbackRequested: true,
            callbackCommittedByAgent: true,
            liveFollowupCreated: false,
            summary: 'Caller asked for a Sales callback.',
            unresolvedQuestions: [],
        ));

        app(VoiceCallFollowupSafetyNet::class)->process($call->fresh());

        $this->assertSame(1, VoiceFollowup::query()->count());
        $this->assertSame([], $this->telegramMessages);
        $analysis = VoiceCallAnalysis::query()->first();
        $this->assertNotNull($analysis);
        $this->assertTrue($analysis->callback_committed_by_agent);
        $this->assertTrue($analysis->live_followup_created);
        $this->assertSame($call->id, VoiceFollowup::query()->value('voice_call_id'));
    }

    #[Test]
    public function promised_callback_without_live_tool_creates_recovered_followup_once(): void
    {
        $call = $this->makeCall('conv_safety_b', [
            ['role' => 'user', 'message' => 'Please ask Sales to call me back on this number any time today.'],
            ['role' => 'agent', 'message' => 'I will pass this request to the sales team.'],
        ]);

        $this->bindAnalyzer(new VoiceCallPostCallAnalysis(
            intent: 'sales_callback',
            department: 'sales',
            humanFollowupRequired: true,
            callbackRequested: true,
            callbackCommittedByAgent: true,
            liveFollowupCreated: false,
            summary: 'Unknown caller asked Sales to call back.',
            unresolvedQuestions: ['application status'],
            callbackPhone: '+15557778888',
            preferredCallbackTime: 'any time during the day',
            reason: 'Sales callback for unidentified application',
        ));

        app(VoiceCallFollowupSafetyNet::class)->process($call->fresh());

        $this->assertSame(1, VoiceFollowup::query()->count());
        $followup = VoiceFollowup::query()->first();
        $this->assertSame(VoiceFollowup::CREATED_BY_SAFETY_NET, $followup->createdBy());
        $this->assertSame('sales', $followup->department);
        $this->assertTrue($followup->callback_requested);
        $this->assertSame('+15557778888', $followup->callback_phone);
        $this->assertNotNull($followup->telegram_sent_at);
        $this->assertSame($call->id, $followup->voice_call_id);

        $this->assertCount(1, $this->telegramMessages);
        $this->assertStringContainsString('⚠️ Voice · Follow-up recovered after call', $this->telegramMessages[0]);
        $this->assertStringContainsString('Department: Sales', $this->telegramMessages[0]);
        $this->assertStringContainsString('call-center?call='.$call->id, $this->telegramMessages[0]);

        app(VoiceCallFollowupSafetyNet::class)->process($call->fresh());
        $this->assertSame(1, VoiceFollowup::query()->count());
        $this->assertCount(1, $this->telegramMessages);
    }

    #[Test]
    public function ordinary_call_does_not_create_followup(): void
    {
        $call = $this->makeCall('conv_safety_c', [
            ['role' => 'user', 'message' => 'When is the Miami show?'],
            ['role' => 'agent', 'message' => 'I will check the public calendar.'],
        ]);

        $this->bindAnalyzer(VoiceCallPostCallAnalysis::none());
        app(VoiceCallFollowupSafetyNet::class)->process($call->fresh());

        $this->assertSame(0, VoiceFollowup::query()->count());
        $this->assertSame([], $this->telegramMessages);
        $analysis = VoiceCallAnalysis::query()->first();
        $this->assertNotNull($analysis);
        $this->assertFalse($analysis->human_followup_required);
        $this->assertFalse($analysis->callback_committed_by_agent);
        $this->assertFalse($analysis->live_followup_created);
    }

    #[Test]
    public function analyze_voice_call_job_runs_safety_net(): void
    {
        $call = $this->makeCall('conv_job');
        $this->bindAnalyzer(new VoiceCallPostCallAnalysis(
            intent: 'callback',
            department: 'sales',
            humanFollowupRequired: true,
            callbackRequested: true,
            callbackCommittedByAgent: true,
            liveFollowupCreated: false,
            summary: 'Need callback',
            unresolvedQuestions: [],
            reason: 'Callback requested',
        ));

        (new AnalyzeVoiceCallJob($call->id))->handle(app(VoiceCallFollowupSafetyNet::class));

        $this->assertSame(1, VoiceFollowup::query()->count());
        $this->assertSame(1, VoiceCallAnalysis::query()->count());
    }

    private function bindAnalyzer(VoiceCallPostCallAnalysis $result): void
    {
        $this->app->instance(VoiceCallPostCallAnalyzer::class, new FakeVoiceCallPostCallAnalyzer($result));
    }

    /**
     * @param  list<array{role: string, message: string}>  $transcript
     */
    private function makeCall(string $conversationId, array $transcript = []): VoiceCall
    {
        $contact = VoiceContact::query()->create([
            'phone_normalized' => '+15551230000',
            'phone_display' => '+15551230000',
            'calls_count' => 1,
            'metadata' => ['elevenlabs_conversation_id' => $conversationId],
        ]);

        return VoiceCall::query()->create([
            'voice_contact_id' => $contact->id,
            'elevenlabs_conversation_id' => $conversationId,
            'phone' => '+15551230000',
            'status' => 'done',
            'transcript' => $transcript,
            'summary' => null,
        ]);
    }

    private function createLiveFollowup(VoiceCall $call, string $conversationId): VoiceFollowup
    {
        return VoiceFollowup::query()->create([
            'voice_call_id' => null,
            'voice_contact_id' => $call->voice_contact_id,
            'elevenlabs_conversation_id' => $conversationId,
            'department' => 'sales',
            'reason' => 'Live callback request',
            'status' => VoiceFollowup::STATUS_OPEN,
            'callback_requested' => true,
            'callback_phone' => '+15551230000',
            'source' => VoiceFollowup::SOURCE_VOICE,
            'telegram_sent_at' => now(),
            'metadata' => ['created_by' => VoiceFollowup::CREATED_BY_LIVE_TOOL],
        ]);
    }
}
