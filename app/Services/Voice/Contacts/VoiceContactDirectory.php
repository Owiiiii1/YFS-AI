<?php

namespace App\Services\Voice\Contacts;

use App\Models\VoiceContact;
use App\Services\Voice\Phone\PhoneNumberNormalizer;

class VoiceContactDirectory
{
    public function __construct(
        private readonly PhoneNumberNormalizer $normalizer,
    ) {}

    public function findOrCreateFromCallerId(?string $callerId): ?VoiceContact
    {
        $display = $this->normalizer->display($callerId);
        $normalized = $this->normalizer->normalize($callerId);
        if ($normalized === '') {
            return null;
        }

        $now = now();
        $contact = VoiceContact::query()->firstOrCreate(
            ['phone_normalized' => $normalized],
            [
                'phone_display' => $display,
                'first_called_at' => $now,
                'last_called_at' => $now,
                'calls_count' => 0,
            ],
        );

        $updates = ['last_called_at' => $now];
        if ($contact->first_called_at === null) {
            $updates['first_called_at'] = $now;
        }
        if (blank($contact->phone_display) && $display !== null) {
            $updates['phone_display'] = $display;
        }

        $contact->forceFill($updates)->save();

        return $contact->fresh();
    }

    public function findByElevenLabsConversationId(?string $conversationId): ?VoiceContact
    {
        $conversationId = trim((string) $conversationId);
        if ($conversationId === '') {
            return null;
        }

        return VoiceContact::query()
            ->where('metadata->elevenlabs_conversation_id', $conversationId)
            ->first();
    }

    public function rememberConversationId(VoiceContact $contact, ?string $conversationId): void
    {
        $conversationId = trim((string) $conversationId);
        if ($conversationId === '') {
            return;
        }

        $metadata = is_array($contact->metadata) ? $contact->metadata : [];
        if (($metadata['elevenlabs_conversation_id'] ?? null) === $conversationId) {
            return;
        }

        $metadata['elevenlabs_conversation_id'] = $conversationId;
        $contact->forceFill(['metadata' => $metadata])->save();
    }
}
