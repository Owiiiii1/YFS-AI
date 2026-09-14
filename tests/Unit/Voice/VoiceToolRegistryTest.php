<?php

namespace Tests\Unit\Voice;

use App\Services\Voice\Exceptions\UnknownVoiceToolException;
use App\Services\Voice\Exceptions\VoiceToolExecutionException;
use App\Services\Voice\Tools\TestVoiceTool;
use App\Services\Voice\Tools\VoiceToolInterface;
use App\Services\Voice\Tools\VoiceToolRegistry;
use App\Services\Voice\VoiceOrchestrator;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class VoiceToolRegistryTest extends TestCase
{
    #[Test]
    public function registry_returns_known_tool(): void
    {
        $tool = new TestVoiceTool();
        $registry = new VoiceToolRegistry([$tool]);

        $this->assertTrue($registry->has(TestVoiceTool::NAME));
        $this->assertSame($tool, $registry->get(TestVoiceTool::NAME));
        $this->assertSame([TestVoiceTool::NAME], $registry->names());
    }

    #[Test]
    public function unknown_tool_is_rejected(): void
    {
        $registry = new VoiceToolRegistry([new TestVoiceTool()]);

        $this->assertFalse($registry->has('not_a_real_tool'));
        $this->expectException(UnknownVoiceToolException::class);
        $registry->get('not_a_real_tool');
    }

    #[Test]
    public function test_tool_returns_explicitly_test_only_context(): void
    {
        $result = (new TestVoiceTool())->execute([]);

        $this->assertSame('yfs_ai_test', $result['source']);
        $this->assertSame('ok', $result['status']);
        $this->assertSame('Voice tool calling is working', $result['message']);
        $this->assertSame('YFS Test Event', $result['event_name']);
        $this->assertSame('test-only', $result['availability']);
        $this->assertArrayNotHasKey('api_key', $result);
        $this->assertArrayNotHasKey('token', $result);
        json_encode($result, JSON_THROW_ON_ERROR);
    }

    #[Test]
    public function orchestrator_executes_known_tool_and_rejects_unknown(): void
    {
        $orchestrator = new VoiceOrchestrator(new VoiceToolRegistry([new TestVoiceTool()]));

        $result = $orchestrator->execute(TestVoiceTool::NAME, ['topic' => 'phone-proof'], 'req-test-1');
        $this->assertSame('YFS Test Event', $result['event_name']);
        $this->assertSame('phone-proof', $result['topic']);

        $this->expectException(UnknownVoiceToolException::class);
        $orchestrator->execute('missing_tool', [], 'req-test-2');
    }

    #[Test]
    public function orchestrator_wraps_tool_exceptions_without_leaking_secrets(): void
    {
        $failing = new class implements VoiceToolInterface
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
        };

        $orchestrator = new VoiceOrchestrator(new VoiceToolRegistry([$failing]));

        try {
            $orchestrator->execute('failing_test_tool', [], 'req-fail');
            $this->fail('Expected VoiceToolExecutionException');
        } catch (VoiceToolExecutionException $exception) {
            $this->assertSame('failing_test_tool', $exception->toolName);
            $this->assertSame('Voice tool execution failed', $exception->getMessage());
            $this->assertStringNotContainsString('secret-credential-must-not-leak', $exception->getMessage());
        }
    }
}
