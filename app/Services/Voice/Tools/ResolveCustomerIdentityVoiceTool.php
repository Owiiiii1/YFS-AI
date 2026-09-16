<?php

namespace App\Services\Voice\Tools;

use App\Services\Voice\Identity\CustomerIdentityResolver;
use App\Services\Voice\Identity\CustomerIdentityResult;
use App\Services\Voice\Identity\VoiceContactSessionResolver;
use App\Services\Voice\Identity\VoiceCustomerIdentityStore;
use Illuminate\Support\Facades\Log;

final class ResolveCustomerIdentityVoiceTool implements VoiceToolInterface, VoiceToolMetadataProvider
{
    public const NAME = 'resolve_customer_identity';

    public function __construct(
        private readonly CustomerIdentityResolver $resolver,
        private readonly VoiceCustomerIdentityStore $identities,
        private readonly VoiceContactSessionResolver $sessions,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Call resolve_customer_identity only when personal/customer-specific information is needed and caller identity is not already uniquely established. '
            .'Use name first. If ambiguous, ask for child name and call again with both name and child_name. '
            .'Do not call this tool for public YFS questions. Do not guess identity. Do not enumerate candidates.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'Caller first and last name as spoken. Prefer this first. At least one of name or child_name is required.',
                ],
                'child_name' => [
                    'type' => 'string',
                    'description' => 'Child first name. Use only after a previous call returned status ambiguous.',
                ],
                'on_behalf_of' => [
                    'type' => 'boolean',
                    'description' => 'Set true only when the caller explicitly says they are calling for a different registered parent or family than the one already identified on this call. Otherwise omit or false.',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function execute(array $arguments): array
    {
        $name = YfsCoreLiveToolSupport::stringArgument($arguments, 'name');
        $childName = YfsCoreLiveToolSupport::stringArgument($arguments, 'child_name');
        $onBehalfOf = $this->boolArgument($arguments, 'on_behalf_of');
        $callerId = YfsCoreLiveToolSupport::stringArgument($arguments, 'system__caller_id');
        $conversationId = YfsCoreLiveToolSupport::stringArgument($arguments, 'system__conversation_id');

        if ($name === '' && $childName === '') {
            $this->logOutcome('invalid_request', null, 0, 'skipped', false);

            return $this->payload(false, 'invalid_request', 'ask_name');
        }

        $result = $this->resolver->resolveBySpokenHintsFast($name, $childName);
        $contact = $this->sessions->findTrusted($callerId !== '' ? $callerId : null, $conversationId !== '' ? $conversationId : null);

        if ($result->status === CustomerIdentityResult::SOURCE_UNAVAILABLE) {
            $this->logOutcome($result->status, $result->matchMethod, $result->matchCount, $contact === null ? 'no_session' : 'unchanged', false);

            return $this->payload(false, CustomerIdentityResult::SOURCE_UNAVAILABLE, 'continue_without_identity');
        }

        $nextAction = $this->nextAction($result, $childName !== '');
        $displayName = $result->displayName;
        $bind = 'skipped';

        if ($contact === null) {
            $bind = 'no_session';
        } elseif ($result->isUnique()) {
            $bind = $this->identities->bindUnique($contact, $result, $onBehalfOf);
            if ($bind === VoiceCustomerIdentityStore::BIND_PRESERVED) {
                $existing = $this->identities->existingUnique($contact);
                $existingName = is_string($existing['display_name'] ?? null) ? trim((string) $existing['display_name']) : '';
                $displayName = $existingName !== '' ? $existingName : $displayName;
                $nextAction = 'already_identified';
            }
        }

        $this->logOutcome($result->status, $result->matchMethod, $result->matchCount, $bind, true);

        return $this->payload(
            true,
            $result->status,
            $nextAction,
            $result->isUnique() ? $displayName : null,
        );
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

    /**
     * @return array{ok: bool, tool: string, status: string, next_action: string, customer?: array{display_name: string}}
     */
    private function payload(bool $ok, string $status, string $nextAction, ?string $displayName = null): array
    {
        $payload = [
            'ok' => $ok,
            'tool' => self::NAME,
            'status' => $status,
            'next_action' => $nextAction,
        ];

        if ($status === CustomerIdentityResult::UNIQUE) {
            $payload['customer'] = [
                'display_name' => is_string($displayName) && trim($displayName) !== ''
                    ? trim($displayName)
                    : 'the identified parent',
            ];
        }

        return $payload;
    }

    private function nextAction(CustomerIdentityResult $result, bool $childProvided): string
    {
        return match ($result->status) {
            CustomerIdentityResult::UNIQUE => 'identified',
            CustomerIdentityResult::AMBIGUOUS => $childProvided ? 'ask_additional_identifier' : 'ask_child_name',
            CustomerIdentityResult::NOT_FOUND => 'ask_again_or_continue_without_identity',
            default => 'continue_without_identity',
        };
    }

    private function boolArgument(array $arguments, string $key): bool
    {
        $value = $arguments[$key] ?? false;
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }
        if (! is_string($value)) {
            return false;
        }

        return in_array(strtolower(trim($value)), ['1', 'true', 'yes'], true);
    }

    private function logOutcome(
        string $status,
        ?string $matchMethod,
        int $matchCount,
        string $bind,
        bool $ok,
    ): void {
        Log::info('voice.tools.resolve_customer_identity', [
            'ok' => $ok,
            'status' => $status,
            'match_method' => $matchMethod,
            'match_count' => $matchCount,
            'bind' => $bind,
        ]);
    }
}
