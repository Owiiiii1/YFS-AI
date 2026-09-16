<?php

namespace App\Services\Voice\Identity;

use App\Jobs\RunExtendedVoiceIdentitySearchJob;
use App\Models\VoiceContact;
use App\Models\VoiceIdentitySearch;
use App\Services\Voice\Tools\YfsCoreLiveToolSupport;
use Illuminate\Support\Facades\Log;

class ExtendedVoiceIdentitySearchService
{
    public function __construct(
        private readonly VoiceCustomerIdentityStore $identities,
    ) {}

    /**
     * @param  array{name?: string, child_name?: string, show_city?: string, package?: string, email?: string}  $hints
     */
    public function start(?VoiceContact $contact, ?string $conversationId, array $hints): VoiceIdentitySearch
    {
        if ($contact !== null) {
            $existing = VoiceIdentitySearch::query()
                ->where('voice_contact_id', $contact->id)
                ->whereIn('status', [VoiceIdentitySearch::PENDING, VoiceIdentitySearch::RUNNING])
                ->where(function ($query): void {
                    $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
                })
                ->latest('id')
                ->first();
            if ($existing !== null) {
                return $existing;
            }
        }

        $search = VoiceIdentitySearch::query()->create([
            'voice_contact_id' => $contact?->id,
            'elevenlabs_conversation_id' => $conversationId !== null && trim($conversationId) !== '' ? trim($conversationId) : null,
            'status' => VoiceIdentitySearch::PENDING,
            'hints' => $this->compactHints($hints),
            'started_at' => now(),
            'expires_at' => now()->addMinutes(30),
        ]);

        RunExtendedVoiceIdentitySearchJob::dispatch($search->id);

        Log::info('voice.identity.extended.started', [
            'status' => $search->status,
            'has_contact' => $contact !== null,
            'has_name' => ($search->hints['has_name'] ?? false) === true,
            'has_child' => ($search->hints['has_child'] ?? false) === true,
        ]);

        return $search;
    }

    public function latestFor(?VoiceContact $contact, ?string $conversationId): ?VoiceIdentitySearch
    {
        $query = VoiceIdentitySearch::query()->orderByDesc('id');
        if ($contact !== null) {
            $query->where('voice_contact_id', $contact->id);
        } elseif ($conversationId !== null && trim($conversationId) !== '') {
            $query->where('elevenlabs_conversation_id', trim($conversationId));
        } else {
            return null;
        }

        $search = $query->first();
        if ($search === null || $search->isExpired()) {
            return $search;
        }

        return $search;
    }

    /**
     * @return array<string, mixed>
     */
    public function publicStatus(?VoiceIdentitySearch $search, ?VoiceContact $contact = null): array
    {
        if ($search === null) {
            return [
                'ok' => true,
                'status' => 'no_search',
                'next_action' => 'continue_without_identity',
            ];
        }

        $status = $search->isExpired() && in_array($search->status, [VoiceIdentitySearch::PENDING, VoiceIdentitySearch::RUNNING], true)
            ? VoiceIdentitySearch::FAILED
            : $search->status;

        $payload = [
            'ok' => true,
            'status' => $status === VoiceIdentitySearch::PENDING || $status === VoiceIdentitySearch::RUNNING
                ? 'searching'
                : $status,
            'next_action' => $this->nextAction($status),
        ];

        if ($status === VoiceIdentitySearch::UNIQUE) {
            $display = $search->result_metadata['display_name'] ?? null;
            if ($contact !== null) {
                $existing = $this->identities->existingUnique($contact);
                if (is_string($existing['display_name'] ?? null) && trim((string) $existing['display_name']) !== '') {
                    $display = $existing['display_name'];
                }
            }
            $payload['customer'] = [
                'display_name' => is_string($display) && trim($display) !== '' ? trim($display) : 'the identified parent',
            ];
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $hints
     * @return array<string, mixed>
     */
    private function compactHints(array $hints): array
    {
        $name = YfsCoreLiveToolSupport::stringArgument($hints, 'name');
        $child = YfsCoreLiveToolSupport::stringArgument($hints, 'child_name');
        $showCity = YfsCoreLiveToolSupport::stringArgument($hints, 'show_city');
        $package = YfsCoreLiveToolSupport::stringArgument($hints, 'package');
        $email = YfsCoreLiveToolSupport::stringArgument($hints, 'email');

        $compact = [
            'has_name' => $name !== '',
            'has_child' => $child !== '',
            'has_show_city' => $showCity !== '',
            'has_package' => $package !== '',
            'has_email' => $email !== '',
        ];
        if ($name !== '') {
            $compact['name'] = $name;
        }
        if ($child !== '') {
            $compact['child_name'] = $child;
        }
        if ($showCity !== '') {
            $compact['show_city'] = $showCity;
        }
        if ($package !== '') {
            $compact['package'] = $package;
        }
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $compact['email'] = mb_strtolower($email);
        }

        return $compact;
    }

    private function nextAction(string $status): string
    {
        return match ($status) {
            VoiceIdentitySearch::UNIQUE => 'identified',
            VoiceIdentitySearch::AMBIGUOUS => 'ask_additional_identifier',
            VoiceIdentitySearch::NOT_FOUND => 'continue_without_identity',
            VoiceIdentitySearch::FAILED => 'continue_without_identity',
            default => 'continue_conversation',
        };
    }
}
