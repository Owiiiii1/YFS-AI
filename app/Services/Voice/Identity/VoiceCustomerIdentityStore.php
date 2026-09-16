<?php

namespace App\Services\Voice\Identity;

use App\Models\VoiceContact;

class VoiceCustomerIdentityStore
{
    public const BIND_BOUND = 'bound';

    public const BIND_PRESERVED = 'preserved';

    public const BIND_SKIPPED = 'skipped';

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
     * Persist a UNIQUE spoken match onto the current VoiceContact.
     * Ambiguous / not_found / unavailable never write through this path.
     * An existing unique identity is not replaced unless $allowReplace is true.
     */
    public function bindUnique(VoiceContact $contact, CustomerIdentityResult $result, bool $allowReplace = false): string
    {
        if (! $result->isUnique() || $result->yfsAppUserId === null) {
            return self::BIND_SKIPPED;
        }

        $existing = $this->existingUnique($contact);
        if ($existing !== null) {
            $same = (int) $existing['app_user_id'] === (int) $result->yfsAppUserId;
            if (! $same && ! $allowReplace) {
                return self::BIND_PRESERVED;
            }
        }

        $this->remember($contact, $result);

        return self::BIND_BOUND;
    }

    /**
     * @return array{status: string, match_method?: string, matched_at?: string, app_user_id: int, display_name?: string}|null
     */
    public function existingUnique(VoiceContact $contact): ?array
    {
        $metadata = is_array($contact->metadata) ? $contact->metadata : [];
        $payload = $metadata['yfs_customer'] ?? null;
        if (! is_array($payload) || ($payload['status'] ?? '') !== CustomerIdentityResult::UNIQUE) {
            return null;
        }
        if (! isset($payload['app_user_id'])) {
            return null;
        }

        $payload['app_user_id'] = (int) $payload['app_user_id'];

        return $payload;
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
