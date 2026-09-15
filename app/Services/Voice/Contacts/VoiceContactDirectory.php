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
}
