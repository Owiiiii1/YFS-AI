<?php

namespace App\Services\Voice\Identity;

use App\Models\VoiceContact;
use App\Models\VoiceIdentitySearch;
use App\Services\Bitrix\BitrixYfsLinker;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExtendedVoiceIdentitySearchRunner
{
    public function __construct(
        private readonly CustomerIdentityResolver $resolver,
        private readonly BitrixYfsLinker $linker,
        private readonly VoiceCustomerIdentityStore $identities,
    ) {}

    public function run(int $searchId): void
    {
        $search = VoiceIdentitySearch::query()->find($searchId);
        if ($search === null || $search->isExpired()) {
            return;
        }
        if (in_array($search->status, [VoiceIdentitySearch::UNIQUE, VoiceIdentitySearch::AMBIGUOUS, VoiceIdentitySearch::NOT_FOUND, VoiceIdentitySearch::FAILED], true)) {
            return;
        }

        $search->forceFill([
            'status' => VoiceIdentitySearch::RUNNING,
            'started_at' => $search->started_at ?? now(),
        ])->save();

        try {
            $result = $this->resolve($search);
            $this->finish($search, $result);
        } catch (Throwable $exception) {
            Log::warning('voice.identity.extended.failed', [
                'status' => VoiceIdentitySearch::FAILED,
            ]);
            $search->forceFill([
                'status' => VoiceIdentitySearch::FAILED,
                'completed_at' => now(),
                'result_metadata' => ['status' => VoiceIdentitySearch::FAILED],
            ])->save();
        }
    }

    private function resolve(VoiceIdentitySearch $search): CustomerIdentityResult
    {
        $hints = is_array($search->hints) ? $search->hints : [];
        $name = trim((string) ($hints['name'] ?? ''));
        $child = trim((string) ($hints['child_name'] ?? ''));
        $email = trim((string) ($hints['email'] ?? ''));

        $contact = $search->voice_contact_id !== null
            ? VoiceContact::query()->find($search->voice_contact_id)
            : null;

        $phone = ($contact !== null && filled($contact->phone_normalized))
            ? (string) $contact->phone_normalized
            : '';

        if ($phone !== '') {
            $byPhone = $this->resolver->resolveByPhoneFast($phone, 8000);
            if ($byPhone->isUnique()) {
                return $byPhone;
            }
        }

        if ($email !== '') {
            $byEmail = $this->linker->resolveFromEmails([$email], 'email');
            if ($byEmail->isUnique()) {
                return $byEmail;
            }
            if ($byEmail->status === CustomerIdentityResult::AMBIGUOUS) {
                return $byEmail;
            }
        }

        if ($name !== '' || $child !== '') {
            $byName = $this->resolver->resolveBySpokenHintsFast(
                $name !== '' ? $name : null,
                $child !== '' ? $child : null,
                8000,
                $phone !== '' ? $phone : null,
            );
            if ($byName->isUnique() || $byName->status === CustomerIdentityResult::AMBIGUOUS) {
                return $byName;
            }
        }

        return CustomerIdentityResult::notFound('extended');
    }

    private function finish(VoiceIdentitySearch $search, CustomerIdentityResult $result): void
    {
        $status = match ($result->status) {
            CustomerIdentityResult::UNIQUE => VoiceIdentitySearch::UNIQUE,
            CustomerIdentityResult::AMBIGUOUS => VoiceIdentitySearch::AMBIGUOUS,
            CustomerIdentityResult::SOURCE_UNAVAILABLE => VoiceIdentitySearch::FAILED,
            default => VoiceIdentitySearch::NOT_FOUND,
        };

        $metadata = [
            'status' => $status,
            'match_method' => $result->matchMethod,
            'match_count' => $result->matchCount,
        ];
        if ($result->isUnique()) {
            $metadata['app_user_id'] = $result->yfsAppUserId;
            $metadata['display_name'] = $result->displayName;
        }

        $search->forceFill([
            'status' => $status,
            'result_metadata' => $metadata,
            'completed_at' => now(),
        ])->save();

        if ($result->isUnique() && $search->voice_contact_id !== null) {
            $contact = VoiceContact::query()->find($search->voice_contact_id);
            if ($contact !== null) {
                $this->identities->bindUnique($contact, $result, false);
            }
        }

        Log::info('voice.identity.extended.completed', [
            'status' => $status,
            'match_method' => $result->matchMethod,
            'match_count' => $result->matchCount,
        ]);
    }
}
