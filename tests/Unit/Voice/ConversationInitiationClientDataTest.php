<?php

namespace Tests\Unit\Voice;

use App\Services\ElevenLabs\ConversationInitiationClientData;
use App\Services\Voice\Prompt\VoiceAssistantPromptBuilder;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ConversationInitiationClientDataTest extends TestCase
{
    #[Test]
    public function payload_matches_elevenlabs_contract_and_uses_builder_prompt(): void
    {
        $assembled = (new VoiceAssistantPromptBuilder)->assemble([
            [
                'key' => 'general',
                'title' => 'General rules',
                'instructions' => 'Use the app first.',
                'sort_order' => 1,
            ],
        ]);

        $payload = ConversationInitiationClientData::fromRuntimePrompt($assembled);

        $this->assertSame('conversation_initiation_client_data', $payload['type']);
        $this->assertSame($assembled->prompt, $payload['conversation_config_override']['agent']['prompt']['prompt']);
        $this->assertSame(['type', 'conversation_config_override'], array_keys($payload));
        $this->assertArrayNotHasKey('dynamic_variables', $payload);
        $this->assertArrayNotHasKey('llm', $payload['conversation_config_override']['agent']['prompt']);
    }
}
