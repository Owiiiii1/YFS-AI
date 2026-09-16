<?php

namespace App\Services\Voice\Tools;

use App\Services\Voice\Identity\ExtendedVoiceIdentitySearchService;
use App\Services\Voice\Identity\VoiceContactSessionResolver;
use Illuminate\Support\Facades\Log;

final class GetExtendedIdentitySearchStatusVoiceTool implements VoiceToolInterface, VoiceToolMetadataProvider
{
    public const NAME = 'get_extended_identity_search_status';

    public function __construct(
        private readonly ExtendedVoiceIdentitySearchService $searches,
        private readonly VoiceContactSessionResolver $sessions,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Check whether a previously started extended identity search has finished. Call this after start_extended_identity_search, not instead of resolve_customer_identity. '
            .'Do not invent progress. If status is searching, continue the conversation. If unique, use customer.display_name. Do not enumerate candidates.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => (object) [],
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
        $search = $this->searches->latestFor($contact, $conversationId !== '' ? $conversationId : null);
        $public = $this->searches->publicStatus($search, $contact);
        $public['ok'] = true;
        $public['tool'] = self::NAME;

        Log::info('voice.tools.get_extended_identity_search_status', [
            'status' => $public['status'],
        ]);

        return $public;
    }

    public function metadata(): VoiceToolMetadata
    {
        return new VoiceToolMetadata(
            category: 'lookup',
            estimatedLatency: 'instant',
            fillerEnabled: false,
            readOnly: true,
            source: YfsCoreLiveToolSupport::SOURCE,
        );
    }
}
