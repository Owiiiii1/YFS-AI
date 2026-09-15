<?php

namespace Tests\Feature;

use App\Models\VoiceCall;
use App\Models\VoiceContact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ElevenLabsPostCallWebhookDatabaseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite is required for isolated feature tests.');
        }

        parent::setUp();

        config([
            'services.elevenlabs.tool_token' => 'test-elevenlabs-tool-token',
            'services.elevenlabs.post_call_webhook_secret' => 'test-post-call-secret',
        ]);
    }

    #[Test]
    public function post_call_webhook_creates_one_voice_call_and_updates_language_memory(): void
    {
        $response = $this->postSigned($this->transcriptionPayload());

        $response->assertOk()
            ->assertJson(['ok' => true])
            ->assertDontSee('test-post-call-secret', false)
            ->assertDontSee('test-elevenlabs-tool-token', false)
            ->assertDontSee('Bearer leaked', false);

        $this->assertSame(1, VoiceCall::query()->count());
        $this->assertSame(1, VoiceContact::query()->count());

        $call = VoiceCall::query()->with('contact')->first();
        $this->assertSame('conv_test_1', $call->elevenlabs_conversation_id);
        $this->assertSame('CA_test', $call->twilio_call_sid);
        $this->assertSame('+15551234567', $call->phone);
        $this->assertSame('ru', $call->language);
        $this->assertSame('done', $call->status);
        $this->assertSame(22, $call->duration_seconds);
        $this->assertSame('Caller asked about tickets.', $call->summary);
        $this->assertNull($call->recording_url);
        $this->assertSame('client', $call->transcript[1]['speaker']);
        $this->assertSame('Hi', $call->transcript[1]['message']);
        $this->assertSame(1, $call->contact->calls_count);
        $this->assertSame('ru', $call->contact->preferred_language);
        $this->assertSame('+15551234567', $call->contact->phone_normalized);

        $metadataJson = json_encode($call->metadata);
        $this->assertStringNotContainsString('test-post-call-secret', $metadataJson);
        $this->assertStringNotContainsString('Bearer leaked', $metadataJson);
        $this->assertStringNotContainsString('SHOULD_NOT_PERSIST', $metadataJson);
        $this->assertStringNotContainsString('authorization_header', $metadataJson);
    }

    #[Test]
    public function duplicate_webhook_is_idempotent_and_does_not_increment_calls_count_again(): void
    {
        $payload = $this->transcriptionPayload();

        $this->postSigned($payload)->assertOk();
        $this->postSigned($payload)->assertOk();

        $this->assertSame(1, VoiceCall::query()->count());
        $this->assertSame(1, VoiceContact::query()->first()->calls_count);
    }

    #[Test]
    public function unknown_language_does_not_overwrite_known_preferred_language(): void
    {
        VoiceContact::query()->create([
            'phone_normalized' => '+15551234567',
            'phone_display' => '+15551234567',
            'preferred_language' => 'uk',
            'calls_count' => 0,
        ]);

        $this->postSigned($this->transcriptionPayload([
            'data' => [
                'metadata' => [
                    'main_language' => 'fr',
                ],
            ],
        ]))->assertOk();

        $contact = VoiceContact::query()->first();
        $this->assertSame('uk', $contact->preferred_language);
        $this->assertNull(VoiceCall::query()->first()->language);
        $this->assertSame(1, $contact->calls_count);
    }

    #[Test]
    public function missing_optional_fields_and_extra_fields_do_not_crash(): void
    {
        $this->postSigned([
            'type' => 'post_call_transcription',
            'unexpected_top_level' => ['foo' => 'bar'],
            'data' => [
                'conversation_id' => 'conv_sparse',
                'extra_future_field' => true,
            ],
        ])->assertOk()->assertJson(['ok' => true]);

        $call = VoiceCall::query()->first();
        $this->assertNotNull($call);
        $this->assertSame('conv_sparse', $call->elevenlabs_conversation_id);
        $this->assertSame('done', $call->status);
        $this->assertSame([], $call->transcript);
        $this->assertNull($call->summary);
        $this->assertNull($call->recording_url);
    }

    #[Test]
    public function audio_webhook_is_acknowledged_without_creating_a_call(): void
    {
        $this->postSigned([
            'type' => 'post_call_audio',
            'data' => [
                'conversation_id' => 'conv_audio',
                'full_audio' => str_repeat('A', 100),
            ],
        ])->assertOk()->assertJson([
            'ok' => true,
            'ignored' => true,
        ]);

        $this->assertSame(0, VoiceCall::query()->count());
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postSigned(array $payload): TestResponse
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $header = 't='.$timestamp.',v0='.hash_hmac('sha256', $timestamp.'.'.$body, 'test-post-call-secret');

        return $this->call('POST', '/api/voice/elevenlabs/post-call', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_ELEVENLABS_SIGNATURE' => $header,
        ], $body);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function transcriptionPayload(array $overrides = []): array
    {
        $payload = [
            'type' => 'post_call_transcription',
            'event_timestamp' => 1739537297,
            'Authorization' => 'Bearer leaked',
            'data' => [
                'agent_id' => 'agent_test',
                'conversation_id' => 'conv_test_1',
                'status' => 'done',
                'transcript' => [
                    ['role' => 'agent', 'message' => 'Hello', 'time_in_call_secs' => 0],
                    ['role' => 'user', 'message' => 'Hi', 'time_in_call_secs' => 2],
                ],
                'metadata' => [
                    'start_time_unix_secs' => 1739537297,
                    'call_duration_secs' => 22,
                    'termination_reason' => 'client_disconnected',
                    'main_language' => 'ru',
                    'authorization_method' => 'authorization_header',
                    'phone_call' => [
                        'type' => 'twilio',
                        'direction' => 'inbound',
                        'external_number' => '+15551234567',
                        'agent_number' => '+15557654321',
                        'call_sid' => 'CA_test',
                    ],
                ],
                'analysis' => [
                    'transcript_summary' => 'Caller asked about tickets.',
                    'call_successful' => 'success',
                ],
                'full_audio' => 'SHOULD_NOT_PERSIST',
            ],
        ];

        return array_replace_recursive($payload, $overrides);
    }
}
