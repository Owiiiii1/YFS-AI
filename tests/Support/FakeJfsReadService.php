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
            foreach ($client['children'] ?? [] as $firstName) {
                if (JfsIdentityMatch::nameMatches((string) $firstName, $childName)) {
                    return true;
                }
            }

            return false;
        });
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
