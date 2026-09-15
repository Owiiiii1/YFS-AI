<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ElevenLabsWebhookToolTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.elevenlabs.tool_token' => 'test-elevenlabs-tool-token']);
        config(['services.voice_runtime.internal_token' => 'test-voice-runtime-token']);
    }

    #[Test]
    public function valid_auth_returns_test_context(): void
    {
        $this->postJson('/api/voice/tools/test-context', [], [
            'Authorization' => 'Bearer test-elevenlabs-tool-token',
        ])->assertOk()
            ->assertExactJson([
                'event_name' => 'YFS Test Event',
                'status' => 'active',
                'message' => 'Voice session context is working',
                'source' => 'yfs_ai_test',
            ])
            ->assertDontSee('test-elevenlabs-tool-token', false)
            ->assertDontSee('test-voice-runtime-token', false);
    }

    #[Test]
    public function missing_auth_returns_401_json(): void
    {
        $this->postJson('/api/voice/tools/test-context')
            ->assertUnauthorized()
            ->assertJson([
                'message' => 'Unauthorized',
            ]);
    }

    #[Test]
    public function wrong_auth_returns_401_json(): void
    {
        $this->postJson('/api/voice/tools/test-context', [], [
            'Authorization' => 'Bearer wrong-token',
        ])->assertUnauthorized()
            ->assertJson([
                'message' => 'Unauthorized',
            ]);
    }

    #[Test]
    public function voice_runtime_token_is_not_accepted(): void
    {
        $this->postJson('/api/voice/tools/test-context', [], [
            'Authorization' => 'Bearer test-voice-runtime-token',
        ])->assertUnauthorized();
    }
}
