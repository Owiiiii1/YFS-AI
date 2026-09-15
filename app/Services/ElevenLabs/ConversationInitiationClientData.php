<?php

namespace App\Services\ElevenLabs;

use App\Services\Voice\Prompt\VoiceAssistantRuntimePrompt;
use App\Support\VoiceSupportedLanguage;

final class ConversationInitiationClientData
{
    public const TYPE = 'conversation_initiation_client_data';

    /**
     * Official ElevenLabs conversation initiation webhook response.
     * Docs: conversation_initiation_client_data with optional conversation_config_override.
     * `type` is included as in the current ElevenLabs examples.
     * System prompt override is always sent. Language is added only when a supported
     * preferred language (en|ru|uk) is known. LLM / voice / first_message are omitted.
     *
     * @return array{
     *     type: string,
     *     conversation_config_override: array{
     *         agent: array{prompt: array{prompt: string}, language?: string}
     *     }
     * }
     */
    public static function fromRuntimePrompt(VoiceAssistantRuntimePrompt $prompt, ?string $language = null): array
    {
        $agent = [
            'prompt' => [
                'prompt' => $prompt->prompt,
            ],
        ];

        $normalized = VoiceSupportedLanguage::tryNormalize($language);
        if ($normalized !== null) {
            $agent['language'] = $normalized;
        }

        return [
            'type' => self::TYPE,
            'conversation_config_override' => [
                'agent' => $agent,
            ],
        ];
    }
}
