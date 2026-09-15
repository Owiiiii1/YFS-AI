<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ElevenLabsPostCallWebhookTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.elevenlabs.tool_token' => 'test-elevenlabs-tool-token',
            'services.elevenlabs.post_call_webhook_secret' => 'test-post-call-secret',
        ]);
    }

    #[Test]
    public function missing_signature_returns_401(): void
    {
        $this->postJson('/api/voice/elevenlabs/post-call', [
            'type' => 'post_call_transcription',
        ])->assertUnauthorized()
            ->assertJson(['message' => 'Unauthorized'])
            ->assertDontSee('test-post-call-secret', false)
            ->assertDontSee('test-elevenlabs-tool-token', false);
    }

    #[Test]
    public function initiation_bearer_token_is_not_accepted(): void
    {
        $this->postJson('/api/voice/elevenlabs/post-call', [
            'type' => 'post_call_transcription',
        ], [
            'Authorization' => 'Bearer test-elevenlabs-tool-token',
        ])->assertUnauthorized();
    }

    #[Test]
    public function empty_secret_fails_closed(): void
    {
        config(['services.elevenlabs.post_call_webhook_secret' => '']);

        $body = '{"type":"post_call_transcription"}';
        $timestamp = (string) time();
        $header = 't='.$timestamp.',v0='.hash_hmac('sha256', $timestamp.'.'.$body, 'test-post-call-secret');

        $this->call('POST', '/api/voice/elevenlabs/post-call', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_ELEVENLABS_SIGNATURE' => $header,
        ], $body)->assertUnauthorized();
    }
}
