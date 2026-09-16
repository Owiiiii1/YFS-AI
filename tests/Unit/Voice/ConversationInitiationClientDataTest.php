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
        $this->assertStringContainsString('get_public_shows', $assembled->prompt);
        $this->assertStringContainsString('get_show_brands', $assembled->prompt);
        $this->assertStringContainsString('E. CALLER IDENTITY', $assembled->prompt);
        $this->assertSame(['type', 'conversation_config_override'], array_keys($payload));
        $this->assertArrayNotHasKey('dynamic_variables', $payload);
        $this->assertArrayNotHasKey('llm', $payload['conversation_config_override']['agent']['prompt']);
        $this->assertArrayNotHasKey('language', $payload['conversation_config_override']['agent']);
        $this->assertArrayNotHasKey('first_message', $payload['conversation_config_override']['agent']);
    }

    #[Test]
    public function supported_language_is_added_without_changing_prompt_structure(): void
    {
        $assembled = (new VoiceAssistantPromptBuilder)->assemble([
            [
                'key' => 'general',
                'title' => 'General rules',
                'instructions' => 'Use the app first.',
                'sort_order' => 1,
            ],
        ]);

        $payload = ConversationInitiationClientData::fromRuntimePrompt($assembled, 'ru');

        $this->assertSame(['type', 'conversation_config_override'], array_keys($payload));
        $this->assertSame($assembled->prompt, $payload['conversation_config_override']['agent']['prompt']['prompt']);
        $this->assertSame('ru', $payload['conversation_config_override']['agent']['language']);
        $this->assertSame(['prompt', 'language'], array_keys($payload['conversation_config_override']['agent']));
        $this->assertArrayNotHasKey('llm', $payload['conversation_config_override']['agent']['prompt']);
    }

    #[Test]
    public function unsupported_language_does_not_create_an_override(): void
    {
        $assembled = (new VoiceAssistantPromptBuilder)->assemble([
            [
                'key' => 'general',
                'title' => 'General rules',
                'instructions' => 'Use the app first.',
                'sort_order' => 1,
            ],
        ]);

        $payload = ConversationInitiationClientData::fromRuntimePrompt($assembled, 'fr');

        $this->assertArrayNotHasKey('language', $payload['conversation_config_override']['agent']);
        $this->assertSame($assembled->prompt, $payload['conversation_config_override']['agent']['prompt']['prompt']);
    }
}
