<?php

namespace Tests\Support;

use App\Services\Jfs\JfsIdentityMatch;
use App\Services\Jfs\JfsReadService;

final class FakeJfsReadService extends JfsReadService
{
    /** @var list<array<string, mixed>> */
    public array $events = [];

    /** @var list<array<string, mixed>> */
    public array $lineups = [];

    /**
     * @var list<array{
     *     id:int,
     *     name:?string,
     *     language:?string,
     *     phone:string,
     *     role?:string,
     *     status?:string,
     *     children?:list<string>
     * }>
     */
    public array $clients = [];

    public bool $configured = true;

    public bool $readFailed = false;

    public int $publicEventsCalls = 0;

    public int $publicBrandLineupsCalls = 0;

    public int $findClientByEmailCalls = 0;

    public int $findClientsByPhoneCalls = 0;

    public int $findClientsByNameCalls = 0;

    public int $findClientsByChildNameCalls = 0;

    public int $findClientsByNameVariantCalls = 0;

    public int $findClientsByChildNameVariantCalls = 0;

    public int $loadCustomerContextCalls = 0;

    public int $writeCalls = 0;

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function lastReadFailed(): bool
    {
        return $this->readFailed || ! $this->configured;
    }

    public function publicEvents(): array
    {
        $this->publicEventsCalls++;

        if ($this->lastReadFailed()) {
            return [];
        }

        return $this->events;
    }

    public function publicBrandLineups(): array
    {
        $this->publicBrandLineupsCalls++;

        if ($this->lastReadFailed()) {
            return [];
        }

        return $this->lineups;
    }

    public function findClientByEmail(string $email): array
    {
        $this->findClientByEmailCalls++;

        $email = mb_strtolower(trim($email));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['status' => 'invalid', 'client' => null, 'children' => []];
        }
        if ($this->lastReadFailed()) {
            return ['status' => 'unavailable', 'client' => null, 'children' => []];
        }

        $matches = [];
        foreach ($this->clients as $client) {
            $stored = isset($client['email']) && is_string($client['email'])
                ? mb_strtolower(trim($client['email']))
                : '';
            if ($stored !== '' && $stored === $email) {
                $matches[] = $client;
            }
        }

        if ($matches === []) {
            return ['status' => 'not_found', 'client' => null, 'children' => []];
        }
        if (count($matches) > 1) {
            return ['status' => 'ambiguous', 'client' => null, 'children' => []];
        }

        $user = $matches[0];

        return [
            'status' => 'found',
            'client' => [
                'id' => (int) $user['id'],
                'name' => isset($user['name']) && is_string($user['name']) ? trim($user['name']) : null,
                'email' => $email,
                'phone' => (string) ($user['phone'] ?? ''),
                'role' => (string) ($user['role'] ?? 'client'),
                'status' => (string) ($user['status'] ?? 'active'),
            ],
            'children' => [],
        ];
    }

    public function findClientsByPhoneDigits(string $phone): array
    {
        $this->findClientsByPhoneCalls++;

        if ($this->lastReadFailed()) {
            return [];
        }

        return $this->identityRecords(function (array $client) use ($phone): bool {
            return JfsIdentityMatch::phonesMatch((string) ($client['phone'] ?? ''), $phone);
        });
    }

    public function findClientsByName(string $name): array
    {
        $this->findClientsByNameCalls++;

        if ($this->lastReadFailed()) {
            return [];
        }

        return $this->identityRecords(function (array $client) use ($name): bool {
            return JfsIdentityMatch::nameMatches((string) ($client['name'] ?? ''), $name);
        });
    }

    public function findClientsByChildName(string $childName): array
    {
        $this->findClientsByChildNameCalls++;

        if ($this->lastReadFailed()) {
            return [];
        }

        return $this->identityRecords(function (array $client) use ($childName): bool {
            foreach ($this->childDisplayNames($client) as $firstName) {
                if (JfsIdentityMatch::nameMatches($firstName, $childName)) {
                    return true;
                }
            }

            return false;
        });
    }

    public function findClientsByNameVariants(string $name): array
    {
        $this->findClientsByNameVariantCalls++;

        if ($this->lastReadFailed()) {
            return [];
        }

        return $this->identityRecords(function (array $client) use ($name): bool {
            $stored = (string) ($client['name'] ?? '');

            return ! JfsIdentityMatch::nameMatches($stored, $name)
                && JfsIdentityMatch::nameMatchesVariant($stored, $name);
        });
    }

    public function findClientsByChildNameVariants(string $childName): array
    {
        $this->findClientsByChildNameVariantCalls++;

        if ($this->lastReadFailed()) {
            return [];
        }

        return $this->identityRecords(function (array $client) use ($childName): bool {
            foreach ($this->childDisplayNames($client) as $firstName) {
                if (! JfsIdentityMatch::nameMatches($firstName, $childName) && JfsIdentityMatch::nameMatchesVariant($firstName, $childName)) {
                    return true;
                }
            }

            return false;
        });
    }

    /**
     * @return array{customer: array{display_name:?string, language:?string}, children: list<array{display_name: string, participations: list<array<string, mixed>>}>}|null
     */
    public function loadCustomerContext(int $appUserId): ?array
    {
        $this->loadCustomerContextCalls++;

        if ($this->lastReadFailed()) {
            return null;
        }

        foreach ($this->clients as $client) {
            if ((int) ($client['id'] ?? 0) !== $appUserId) {
                continue;
            }
            if (($client['role'] ?? 'client') !== 'client') {
                return null;
            }
            if (($client['status'] ?? 'active') === 'blocked') {
                return null;
            }

            $children = [];
            foreach ($client['children'] ?? [] as $child) {
                if (is_string($child)) {
                    $name = trim($child);
                    if ($name === '') {
                        continue;
                    }
                    $children[] = [
                        'display_name' => $name,
                        'participations' => [],
                    ];

                    continue;
                }
                if (! is_array($child)) {
                    continue;
                }
                $name = trim((string) ($child['display_name'] ?? $child['first_name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $participations = [];
                foreach ($child['participations'] ?? [] as $participation) {
                    if (is_array($participation)) {
                        $participations[] = $participation;
                    }
                }
                $children[] = [
                    'display_name' => $name,
                    'participations' => $participations,
                ];
            }

            $name = isset($client['name']) && is_string($client['name']) ? trim($client['name']) : '';
            $language = isset($client['language']) && is_string($client['language']) ? trim($client['language']) : '';

            return [
                'customer' => [
                    'display_name' => $name !== '' ? $name : null,
                    'language' => $language !== '' ? $language : null,
                ],
                'children' => $children,
            ];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $client
     * @return list<string>
     */
    private function childDisplayNames(array $client): array
    {
        $names = [];
        foreach ($client['children'] ?? [] as $child) {
            if (is_string($child)) {
                $names[] = $child;

                continue;
            }
            if (is_array($child)) {
                $names[] = (string) ($child['display_name'] ?? $child['first_name'] ?? '');
            }
        }

        return $names;
    }

    /**
     * @param  callable(array<string, mixed>): bool  $matches
     * @return list<array{id:int,name:?string,language:?string}>
     */
    private function identityRecords(callable $matches): array
    {
        $records = [];
        foreach ($this->clients as $client) {
            if (($client['role'] ?? 'client') !== 'client') {
                continue;
            }
            if (($client['status'] ?? 'active') === 'blocked') {
                continue;
            }
            if (! $matches($client)) {
                continue;
            }
            $records[(int) $client['id']] = [
                'id' => (int) $client['id'],
                'name' => isset($client['name']) && is_string($client['name']) && trim($client['name']) !== ''
                    ? trim($client['name'])
                    : null,
                'language' => isset($client['language']) && is_string($client['language']) && trim($client['language']) !== ''
                    ? trim($client['language'])
                    : null,
            ];
        }

        return array_values($records);
    }
}
