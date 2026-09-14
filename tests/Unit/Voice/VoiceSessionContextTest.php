<?php

namespace Tests\Unit\Voice;

use App\Services\Voice\Context\VoiceSessionContextFactory;
use App\Services\Voice\Filler\VoiceFillerPhraseService;
use App\Services\Voice\Prompt\VoiceLanguageDetector;
use App\Services\Voice\Prompt\VoicePromptOrchestrator;
use App\Services\Voice\Prompt\VoiceTopicResolver;
use App\Services\Voice\Tools\TestVoiceTool;
use App\Services\Voice\Tools\VoiceToolMetadataResolver;
use App\Services\Voice\Tools\VoiceToolRegistry;
use App\Services\Voice\VoiceSessionTurnService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VoiceSessionContextTest extends TestCase
{
    #[Test]
    public function synthetic_session_context_preloads_test_event_without_credentials(): void
    {
        $context = (new VoiceSessionContextFactory(
            new VoiceLanguageDetector(),
            new VoiceTopicResolver(),
        ))->make('sess-1', 'What is the YFS test event?');

        $this->assertSame('sess-1', $context->sessionId);
        $this->assertSame('en', $context->detectedLanguage);
        $this->assertSame('test_event', $context->currentTopic);
        $this->assertNull($context->customerContext);
        $this->assertNull($context->bitrixContext);
        $this->assertSame('YFS Test Event', $context->yfsContext['event_name'] ?? null);
        $this->assertSame('Voice session context is working.', $context->yfsContext['message'] ?? null);
        $this->assertSame('test-only', $context->yfsContext['availability'] ?? null);
        $this->assertContains('yfs', $context->sectionNames());
        $encoded = json_encode($context->toArray()) ?: '';
        $this->assertStringNotContainsString('api_key', $encoded);
        $this->assertStringNotContainsString('Bearer', $encoded);
    }

    #[Test]
    public function prompt_orchestrator_uses_fast_path_for_preloaded_test_event(): void
    {
        $context = (new VoiceSessionContextFactory(
            new VoiceLanguageDetector(),
            new VoiceTopicResolver(),
        ))->make('sess-1', 'What is the YFS test event?');
        $assembled = (new VoicePromptOrchestrator())->assemble($context, 'What is the YFS test event?');

        $this->assertSame('test_event', $assembled->activeTopic);
        $this->assertSame('fast', $assembled->responsePath);
        $this->assertSame([], $assembled->allowedTools);
        $this->assertContains('global', $assembled->sectionNames);
        $this->assertContains('session', $assembled->sectionNames);
        $this->assertContains('topic:test_event', $assembled->sectionNames);
        $this->assertStringContainsString('YFS Test Event', $assembled->text);
        $this->assertStringContainsString('Voice session context is working.', $assembled->text);
        $this->assertStringContainsString('Do not call tools', $assembled->text);
        $this->assertGreaterThan(0, $assembled->promptChars);
        $this->assertSame(mb_strlen($assembled->text), $assembled->promptChars);
    }

    #[Test]
    public function prompt_orchestrator_requires_tool_for_latest_test_status(): void
    {
        $context = (new VoiceSessionContextFactory(
            new VoiceLanguageDetector(),
            new VoiceTopicResolver(),
        ))->make('sess-2', 'Check the latest YFS test status.');
        $assembled = (new VoicePromptOrchestrator())->assemble($context, 'Check the latest YFS test status.');

        $this->assertSame('test_status', $assembled->activeTopic);
        $this->assertSame('tool', $assembled->responsePath);
        $this->assertSame([TestVoiceTool::NAME], $assembled->allowedTools);
        $this->assertStringContainsString('MUST call get_current_yfs_test_context', $assembled->text);
    }

    #[Test]
    public function filler_phrases_are_short_language_specific_and_rotate(): void
    {
        $service = new VoiceFillerPhraseService();
        $en = $service->select('en', 'lookup', 'a');
        $ru = $service->select('ru', 'lookup', 'a');
        $uk = $service->select('uk', 'lookup', 'a');

        $this->assertSame('en', $en->language);
        $this->assertStringEndsWith('... ', $en->text);
        $this->assertLessThanOrEqual(80, mb_strlen(trim($en->text)));
        $this->assertStringEndsWith('... ', $ru->text);
        $this->assertStringEndsWith('... ', $uk->text);
        $this->assertNotSame($en->id, $ru->id);
        $ids = [];
        for ($i = 0; $i < 12; $i++) {
            $ids[] = $service->select('en', 'lookup', (string) $i)->id;
        }
        $this->assertGreaterThan(1, count(array_unique($ids)));
    }

    #[Test]
    public function test_tool_exposes_lookup_metadata(): void
    {
        $metadata = (new VoiceToolMetadataResolver())->resolve(new TestVoiceTool());
        $this->assertSame('lookup', $metadata->category);
        $this->assertSame('short', $metadata->estimatedLatency);
        $this->assertTrue($metadata->fillerEnabled);
        $this->assertTrue($metadata->readOnly);
        $this->assertSame('yfs_ai_test', $metadata->source);
    }

    #[Test]
    public function turn_service_returns_fast_path_payload_without_secrets(): void
    {
        $service = new VoiceSessionTurnService(
            new VoiceSessionContextFactory(new VoiceLanguageDetector(), new VoiceTopicResolver()),
            new VoicePromptOrchestrator(),
            new VoiceFillerPhraseService(),
            new VoiceToolRegistry([new TestVoiceTool()]),
            new VoiceToolMetadataResolver(),
        );

        $payload = $service->assemble('sess-fast', 'What is the YFS test event?', 'req-1');
        $this->assertTrue($payload['ok']);
        $this->assertSame('fast', $payload['response_path']);
        $this->assertSame([], $payload['allowed_tools']);
        $this->assertSame([], $payload['fillers']);
        $encoded = json_encode($payload) ?: '';
        $this->assertStringNotContainsString('VOICE_RUNTIME', $encoded);
        $this->assertStringNotContainsString('api_key', $encoded);
    }
}
