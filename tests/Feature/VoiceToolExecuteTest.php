<?php

namespace Tests\Feature;

use App\Services\Voice\Tools\TestVoiceTool;
use App\Services\Voice\Tools\VoiceToolInterface;
use App\Services\Voice\Tools\VoiceToolRegistry;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class VoiceToolExecuteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.voice_runtime.internal_token' => 'test-voice-runtime-token']);
    }

    #[Test]
    public function internal_auth_is_required(): void
    {
        $this->postJson('/api/internal/voice/tools/execute', [
            'tool' => TestVoiceTool::NAME,
            'arguments' => [],
        ])->assertUnauthorized();

        $this->postJson('/api/internal/voice/tools/execute', [
            'tool' => TestVoiceTool::NAME,
            'arguments' => [],
        ], [
            'Authorization' => 'Bearer wrong-token',
        ])->assertUnauthorized();
    }

    #[Test]
    public function known_tool_executes_successfully(): void
    {
        $this->postJson('/api/internal/voice/tools/execute', [
            'tool' => TestVoiceTool::NAME,
            'arguments' => ['topic' => 'live-proof'],
        ], [
            'Authorization' => 'Bearer test-voice-runtime-token',
            'X-Request-Id' => 'voice-test-request-1',
        ])->assertOk()->assertJson([
            'ok' => true,
            'tool' => TestVoiceTool::NAME,
            'result' => [
                'source' => 'yfs_ai_test',
                'status' => 'ok',
                'message' => 'Voice tool calling is working',
                'event_name' => 'YFS Test Event',
                'availability' => 'test-only',
            ],
        ])->assertJsonMissingPath('result.api_key')
            ->assertDontSee('test-voice-runtime-token', false);
    }

    #[Test]
    public function unknown_tool_returns_controlled_json(): void
    {
        $this->postJson('/api/internal/voice/tools/execute', [
            'tool' => 'not_a_real_voice_tool',
            'arguments' => [],
        ], [
            'Authorization' => 'Bearer test-voice-runtime-token',
        ])->assertNotFound()->assertJson([
            'ok' => false,
            'tool' => 'not_a_real_voice_tool',
            'error' => [
                'type' => 'unknown_tool',
                'message' => 'Unknown voice tool',
            ],
        ])->assertJsonMissingPath('exception')
            ->assertJsonMissingPath('trace');
    }

    #[Test]
    public function tool_execution_error_returns_controlled_json_without_secrets(): void
    {
        $this->app->singleton(VoiceToolRegistry::class, function () {
            return new VoiceToolRegistry([
                new class implements VoiceToolInterface
                {
                    public function name(): string
                    {
                        return 'failing_test_tool';
                    }

                    public function description(): string
                    {
                        return 'Fails on purpose';
                    }

                    public function inputSchema(): array
                    {
                        return ['type' => 'object'];
                    }

                    public function execute(array $arguments): array
                    {
                        throw new RuntimeException('secret-credential-must-not-leak');
                    }
                },
            ]);
        });

        $this->postJson('/api/internal/voice/tools/execute', [
            'tool' => 'failing_test_tool',
            'arguments' => [],
        ], [
            'Authorization' => 'Bearer test-voice-runtime-token',
        ])->assertStatus(500)
            ->assertJson([
                'ok' => false,
                'tool' => 'failing_test_tool',
                'error' => [
                    'type' => 'tool_failed',
                    'message' => 'Voice tool execution failed',
                ],
            ])
            ->assertDontSee('secret-credential-must-not-leak', false)
            ->assertJsonMissingPath('trace');
    }
}
