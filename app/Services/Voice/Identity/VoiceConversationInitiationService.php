<?php

namespace App\Services\Voice\Identity;

use App\Services\ElevenLabs\ConversationInitiationClientData;
use App\Services\Voice\Contacts\VoiceContactDirectory;
use App\Services\Voice\Prompt\VoiceAssistantPromptBuilder;
use App\Support\VoiceSupportedLanguage;

final class VoiceConversationInitiationService
{
    public function __construct(
        private readonly VoiceContactDirectory $directory,
        private readonly CustomerIdentityResolver $resolver,
        private readonly VoiceCustomerIdentityStore $identityStore,
        private readonly VoiceAssistantPromptBuilder $promptBuilder,
    ) {}

    /**
     * @return array{
     *     type: string,
     *     conversation_config_override: array{
     *         agent: array{prompt: array{prompt: string}, language?: string}
     *     }
     * }
     */
    public function payload(?string $callerId, ?string $conversationId = null): array
    {
        $contact = $this->directory->findOrCreateFromCallerId($callerId);
        $identity = $this->resolver->resolveByPhoneFast($callerId);

        if ($contact !== null) {
            $this->identityStore->remember($contact, $identity);
            $contact = $contact->fresh() ?? $contact;
            if (trim((string) $conversationId) !== '') {
                $this->directory->rememberConversationId($contact, $conversationId);
                $contact = $contact->fresh() ?? $contact;
            }
        }

        $language = VoiceSupportedLanguage::tryNormalize($contact?->preferred_language);
        if ($language === null && $identity->isUnique()) {
            $language = VoiceSupportedLanguage::tryNormalize($identity->preferredLanguage);
        }

        return ConversationInitiationClientData::fromRuntimePrompt(
            $this->promptBuilder->build(identity: $identity),
            $language,
        );
    }
}
