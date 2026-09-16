<?php

namespace App\Services\Voice\Tools;

use App\Services\Voice\Identity\ExtendedVoiceIdentitySearchService;
use App\Services\Voice\Identity\VoiceContactSessionResolver;
use App\Services\Voice\Identity\VoiceCustomerIdentityStore;
use Illuminate\Support\Facades\Log;

final class StartExtendedIdentitySearchVoiceTool implements VoiceToolInterface, VoiceToolMetadataProvider
{
    public const NAME = 'start_extended_identity_search';

    public function __construct(
        private readonly ExtendedVoiceIdentitySearchService $searches,
        private readonly VoiceContactSessionResolver $sessions,
        private readonly VoiceCustomerIdentityStore $identities,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Start a background identity search only after resolve_customer_identity did not uniquely identify the caller and personal information is still needed. '
            .'Returns immediately with status searching. Do not wait silently. Continue the conversation and later call get_extended_identity_search_status. '
            .'Do not call for public show questions. Do not guess identity.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'Caller first and last name if known.',
                ],
                'child_name' => [
                    'type' => 'string',
                    'description' => 'Child first name if known.',
                ],
                'show_city' => [
                    'type' => 'string',
                    'description' => 'Show city if the caller named one.',
                ],
                'package' => [
                    'type' => 'string',
                    'description' => 'Package name if the caller named one.',
                ],
                'email' => [
                    'type' => 'string',
                    'description' => 'Email only if the caller explicitly said it. Never invent an email.',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function execute(array $arguments): array
    {
        $callerId = YfsCoreLiveToolSupport::stringArgument($arguments, 'system__caller_id');
        $conversationId = YfsCoreLiveToolSupport::stringArgument($arguments, 'system__conversation_id');
        $contact = $this->sessions->findTrusted(
            $callerId !== '' ? $callerId : null,
            $conversationId !== '' ? $conversationId : null,
        );

        if ($contact !== null && $this->identities->existingUnique($contact) !== null) {
            $existing = $this->identities->existingUnique($contact);

            return [
                'ok' => true,
                'tool' => self::NAME,
                'status' => 'unique',
                'next_action' => 'already_identified',
                'customer' => [
                    'display_name' => is_string($existing['display_name'] ?? null) && trim((string) $existing['display_name']) !== ''
                        ? trim((string) $existing['display_name'])
                        : 'the identified parent',
                ],
            ];
        }

        if ($contact === null) {
            Log::info('voice.tools.start_extended_identity_search', ['status' => 'no_session']);

            return [
                'ok' => false,
                'tool' => self::NAME,
                'status' => 'no_session',
                'next_action' => 'continue_without_identity',
            ];
        }

        $search = $this->searches->start($contact, $conversationId !== '' ? $conversationId : null, $arguments);
        $public = $this->searches->publicStatus($search, $contact);
        $public['ok'] = true;
        $public['tool'] = self::NAME;

        Log::info('voice.tools.start_extended_identity_search', [
            'status' => $public['status'],
        ]);

        return $public;
    }

    public function metadata(): VoiceToolMetadata
    {
        return new VoiceToolMetadata(
            category: 'lookup',
            estimatedLatency: 'short',
            fillerEnabled: true,
            readOnly: true,
            source: YfsCoreLiveToolSupport::SOURCE,
        );
    }
}
