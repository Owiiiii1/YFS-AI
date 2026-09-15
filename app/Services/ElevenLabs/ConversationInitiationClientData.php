<?php

namespace App\Services\ElevenLabs;

use App\Services\Voice\Prompt\VoiceAssistantRuntimePrompt;

final class ConversationInitiationClientData
{
    public const TYPE = 'conversation_initiation_client_data';

    /**
     * Official ElevenLabs conversation initiation webhook response.
     * Docs: conversation_initiation_client_data with optional conversation_config_override.
     * `type` is included as in the current ElevenLabs examples.
     * Overrides besides system prompt are omitted so this adapter does not change LLM, voice, or first message.
     *
     * @return array{
     *     type: string,
     *     conversation_config_override: array{
     *         agent: array{prompt: array{prompt: string}}
     *     }
     * }
     */
    public static function fromRuntimePrompt(VoiceAssistantRuntimePrompt $prompt): array
    {
        return [
            'type' => self::TYPE,
            'conversation_config_override' => [
                'agent' => [
                    'prompt' => [
                        'prompt' => $prompt->prompt,
                    ],
                ],
            ],
        ];
    }
}
