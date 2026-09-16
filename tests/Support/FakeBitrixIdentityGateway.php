<?php

namespace Tests\Support;

use App\Services\Bitrix\BitrixEntityMatch;
use App\Services\Bitrix\BitrixIdentityGateway;

final class FakeBitrixIdentityGateway implements BitrixIdentityGateway
{
    public bool $configured = true;

    public bool $unavailable = false;

    /** @var array<string, list<int>> */
    public array $phoneToContactIds = [];

    /** @var array<string, list<int>> */
    public array $nameToContactIds = [];

    /** @var array<string, list<int>> */
    public array $childToContactIds = [];

    /** @var array<int, list<string>> */
    public array $contactEmails = [];

    public int $phoneLookups = 0;

    public int $nameLookups = 0;

    public int $childLookups = 0;

    public int $emailLookups = 0;

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function scopes(): array
    {
        return ['crm', 'telephony', 'call', 'user'];
    }

    public function findContactIdsByPhone(?string $phone): BitrixEntityMatch
    {
        $this->phoneLookups++;
        if ($this->unavailable || ! $this->configured) {
            return BitrixEntityMatch::unavailable(1);
        }

        return $this->fromIds($this->lookup($this->phoneToContactIds, $phone));
    }

    public function findContactIdsByName(?string $name): BitrixEntityMatch
    {
        $this->nameLookups++;
        if ($this->unavailable || ! $this->configured) {
            return BitrixEntityMatch::unavailable(1);
        }

        return $this->fromIds($this->lookup($this->nameToContactIds, $name));
    }

    public function findContactIdsByChildName(?string $childName): BitrixEntityMatch
    {
        $this->childLookups++;
        if ($this->unavailable || ! $this->configured) {
            return BitrixEntityMatch::unavailable(1);
        }

        return $this->fromIds($this->lookup($this->childToContactIds, $childName));
    }

    public function emailsForContactIds(array $contactIds, int $limit = 3): array
    {
        $this->emailLookups++;
        $emails = [];
        foreach (array_slice($contactIds, 0, $limit) as $id) {
            foreach ($this->contactEmails[(int) $id] ?? [] as $email) {
                $emails[] = $email;
            }
        }

        return array_values(array_unique($emails));
    }

    /**
     * @param  array<string, list<int>>  $map
     * @return list<int>
     */
    private function lookup(array $map, ?string $key): array
    {
        $normalized = mb_strtolower(trim((string) $key));
        if ($normalized === '') {
            return [];
        }
        if (isset($map[$normalized])) {
            return $map[$normalized];
        }
        $digits = preg_replace('/\D+/', '', (string) $key) ?? '';

        return $map[$digits] ?? [];
    }

    /**
     * @param  list<int>  $ids
     */
    private function fromIds(array $ids): BitrixEntityMatch
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $count = count($ids);
        if ($count === 0) {
            return BitrixEntityMatch::notFound(1);
        }
        if ($count === 1) {
            return BitrixEntityMatch::unique($ids, 1);
        }

        return BitrixEntityMatch::ambiguous($ids, 1);
    }
}
