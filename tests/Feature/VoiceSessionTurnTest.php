<?php

namespace Tests\Feature;

use App\Services\Voice\Tools\TestVoiceTool;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VoiceSessionTurnTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.voice_runtime.internal_token' => 'test-voice-runtime-token']);
        config(['services.voice_runtime.filler_enabled' => true]);
    }

    #[Test]
    public function session_turn_requires_internal_auth(): void
    {
        $this->postJson('/api/internal/voice/session/turn', [
            'user_text' => 'What is the YFS test event?',
        ])->assertUnauthorized();
    }

    #[Test]
    public function session_turn_fast_path_for_preloaded_test_event(): void
    {
        $this->postJson('/api/internal/voice/session/turn', [
            'session_id' => 'live-fast',
            'user_text' => 'What is the YFS test event?',
        ], [
            'Authorization' => 'Bearer test-voice-runtime-token',
            'X-Request-Id' => 'turn-fast-1',
        ])->assertOk()->assertJson([
            'ok' => true,
            'active_topic' => 'test_event',
            'response_path' => 'fast',
            'allowed_tools' => [],
        ])->assertJsonPath('section_names.0', 'global')
            ->assertDontSee('test-voice-runtime-token', false);
    }

    #[Test]
    public function session_turn_tool_path_includes_filler_for_latest_status(): void
    {
        $this->postJson('/api/internal/voice/session/turn', [
            'session_id' => 'live-tool',
            'user_text' => 'Check the latest YFS test status.',
            'language' => 'en',
        ], [
            'Authorization' => 'Bearer test-voice-runtime-token',
        ])->assertOk()->assertJson([
            'ok' => true,
            'active_topic' => 'test_status',
            'response_path' => 'tool',
            'allowed_tools' => [TestVoiceTool::NAME],
        ])->assertJsonPath('fillers.'.TestVoiceTool::NAME.'.enabled', true)
            ->assertJsonPath('fillers.'.TestVoiceTool::NAME.'.category', 'lookup')
            ->assertJsonPath('tool_metadata.'.TestVoiceTool::NAME.'.source', 'yfs_ai_test');
    }
}
