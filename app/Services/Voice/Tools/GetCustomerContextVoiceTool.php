<?php

namespace App\Services\Voice\Tools;

use App\Services\Jfs\JfsReadService;
use App\Services\Voice\Identity\VoiceContactSessionResolver;
use App\Services\Voice\Identity\VoiceCustomerIdentityStore;
use Illuminate\Support\Facades\Log;

final class GetCustomerContextVoiceTool implements VoiceToolInterface, VoiceToolMetadataProvider
{
    public const NAME = 'get_customer_context';

    public const STATUS_OK = 'ok';

    public const STATUS_IDENTITY_REQUIRED = 'identity_required';

    public const STATUS_UNAVAILABLE = 'unavailable';

    public function __construct(
        private readonly JfsReadService $jfs,
        private readonly VoiceContactSessionResolver $sessions,
        private readonly VoiceCustomerIdentityStore $identities,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Returns compact personal YFS Core context for the already identified caller on this conversation: customer display name, children, and show participations. '
            .'Call this when the question is about the caller, their children, registrations, or which shows a child took part in. '
            .'Do not say personal data is unavailable until this tool has run. '
            .'Do not pass customer_id, name, email, phone, or child_id. Identity comes only from the current conversation. '
            .'If status is identity_required, identify the caller with resolve_customer_identity first. '
            .'Answer only the asked question from the result. Do not read the whole JSON. '
            .'If several children are listed and the caller said “my child”, ask which child.';
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
        $contact = $this->sessions->findExistingTrusted(
            $callerId !== '' ? $callerId : null,
            $conversationId !== '' ? $conversationId : null,
        );
        $bound = $contact !== null ? $this->identities->existingUnique($contact) : null;

        if ($bound === null) {
            $this->logOutcome(self::STATUS_IDENTITY_REQUIRED, true);

            return $this->payload(true, self::STATUS_IDENTITY_REQUIRED);
        }

        $context = $this->jfs->loadCustomerContext((int) $bound['app_user_id']);
        if ($this->jfs->lastReadFailed()) {
            $this->logOutcome(self::STATUS_UNAVAILABLE, false);

            return $this->payload(false, self::STATUS_UNAVAILABLE);
        }
        if ($context === null) {
            $this->logOutcome(self::STATUS_IDENTITY_REQUIRED, true);

            return $this->payload(true, self::STATUS_IDENTITY_REQUIRED);
        }

        $customer = $this->publicCustomer($context['customer'], $bound);
        $children = $this->publicChildren($context['children']);
        $package = $this->uniquePackage($children);
        if ($package !== null) {
            $customer['package'] = $package;
        }

        $this->logOutcome(self::STATUS_OK, true);

        return $this->payload(true, self::STATUS_OK, $customer, $children);
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
     * @param  array{display_name:?string, language:?string}  $customer
     * @param  array{app_user_id:int, display_name?:string|null}  $bound
     * @return array{display_name: string, preferred_language?: string, package?: string}
     */
    private function publicCustomer(array $customer, array $bound): array
    {
        $name = trim((string) ($customer['display_name'] ?? ''));
        if ($name === '') {
            $name = trim((string) ($bound['display_name'] ?? ''));
        }

        $payload = [
            'display_name' => $name !== '' ? $name : 'the identified parent',
        ];
        $language = trim((string) ($customer['language'] ?? ''));
        if ($language !== '') {
            $payload['preferred_language'] = $language;
        }

        return $payload;
    }

    /**
     * @param  list<array{display_name:string, participations?:list<array<string, mixed>>}>  $children
     * @return list<array{display_name: string, participations: list<array<string, mixed>>}>
     */
    private function publicChildren(array $children): array
    {
        $public = [];
        foreach ($children as $child) {
            $name = trim((string) ($child['display_name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $public[] = [
                'display_name' => $name,
                'participations' => $this->publicParticipations($child['participations'] ?? []),
            ];
        }

        return $public;
    }

    /**
     * @param  list<array<string, mixed>>  $participations
     * @return list<array<string, mixed>>
     */
    private function publicParticipations(array $participations): array
    {
        $public = [];
        foreach ($participations as $row) {
            $show = trim((string) ($row['show'] ?? ''));
            $city = trim((string) ($row['city'] ?? ''));
            if ($show === '' && $city === '') {
                continue;
            }
            $item = [
                'show' => $show,
                'city' => $city,
                'date' => $this->nullableString($row['date'] ?? null),
                'date_announced' => (bool) ($row['date_announced'] ?? false),
                'is_past' => (bool) ($row['is_past'] ?? false),
            ];
            $status = trim((string) ($row['status'] ?? ''));
            if ($status !== '') {
                $item['status'] = $status;
            }
            $category = trim((string) ($row['category'] ?? ''));
            if ($category !== '') {
                $item['category'] = $category;
            }
            $package = trim((string) ($row['package'] ?? ''));
            if ($package !== '') {
                $item['package'] = $package;
            }
            $public[] = $item;
        }

        return $public;
    }

    /**
     * @param  list<array{participations: list<array<string, mixed>>}>  $children
     */
    private function uniquePackage(array $children): ?string
    {
        $names = [];
        foreach ($children as $child) {
            foreach ($child['participations'] as $participation) {
                $package = trim((string) ($participation['package'] ?? ''));
                if ($package !== '') {
                    $names[$package] = true;
                }
            }
        }
        $keys = array_keys($names);

        return count($keys) === 1 ? $keys[0] : null;
    }

    /**
     * @param  list<array{display_name: string, participations: list<array<string, mixed>>}>|null  $children
     * @return array{ok: bool, tool: string, status: string, customer?: array<string, mixed>, children?: list<array<string, mixed>>}
     */
    private function payload(bool $ok, string $status, ?array $customer = null, ?array $children = null): array
    {
        $payload = [
            'ok' => $ok,
            'tool' => self::NAME,
            'status' => $status,
        ];
        if ($status === self::STATUS_OK && $customer !== null && $children !== null) {
            $payload['customer'] = $customer;
            $payload['children'] = $children;
        }

        return $payload;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $text = trim($value);

        return $text === '' ? null : $text;
    }

    private function logOutcome(string $status, bool $ok): void
    {
        Log::info('voice.tools.get_customer_context', [
            'ok' => $ok,
            'status' => $status,
        ]);
    }
}
