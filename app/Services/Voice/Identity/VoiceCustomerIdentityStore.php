<?php

namespace App\Services\Voice\Identity;

use App\Models\VoiceContact;

class VoiceCustomerIdentityStore
{
    public function remember(VoiceContact $contact, CustomerIdentityResult $result): void
    {
        if ($result->status === CustomerIdentityResult::SOURCE_UNAVAILABLE) {
            return;
        }

        $metadata = is_array($contact->metadata) ? $contact->metadata : [];
        $metadata['yfs_customer'] = $this->compact($result);
        $contact->forceFill(['metadata' => $metadata])->save();
    }

    /**
     * @return array<string, mixed>
     */
    public function compact(CustomerIdentityResult $result): array
    {
        $payload = [
            'status' => $result->status,
            'match_method' => $result->matchMethod,
            'matched_at' => now()->utc()->toIso8601String(),
        ];

        if ($result->isUnique()) {
            $payload['app_user_id'] = $result->yfsAppUserId;
            $payload['display_name'] = $result->displayName;
        } elseif ($result->status === CustomerIdentityResult::AMBIGUOUS) {
            $payload['candidate_count'] = $result->matchCount;
        }

        return $payload;
    }
}
