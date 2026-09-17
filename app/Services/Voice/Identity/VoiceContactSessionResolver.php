<?php

namespace App\Services\Voice\Identity;

use App\Models\VoiceContact;
use App\Services\Voice\Contacts\VoiceContactDirectory;

class VoiceContactSessionResolver
{
    public function __construct(
        private readonly VoiceContactDirectory $directory,
    ) {}

    /**
     * Resolve the current caller only from ElevenLabs system identifiers.
     * LLM-generated phone / caller_id values are never accepted here.
     */
    public function findTrusted(?string $systemCallerId, ?string $systemConversationId): ?VoiceContact
    {
        $byConversation = $this->directory->findByElevenLabsConversationId($systemConversationId);
        if ($byConversation !== null) {
            return $byConversation;
        }

        $callerId = trim((string) $systemCallerId);
        if ($callerId === '') {
            return null;
        }

        return $this->directory->findOrCreateFromCallerId($callerId);
    }

    /**
     * Find an existing VoiceContact without creating one.
     * Conversation id wins over caller id so a second number cannot swap identity.
     */
    public function findExistingTrusted(?string $systemCallerId, ?string $systemConversationId): ?VoiceContact
    {
        $byConversation = $this->directory->findByElevenLabsConversationId($systemConversationId);
        if ($byConversation !== null) {
            return $byConversation;
        }

        return $this->directory->findByCallerId($systemCallerId);
    }
}
