<?php

namespace App\Services\Bitrix;

interface BitrixIdentityGateway
{
    public function isConfigured(): bool;

    /**
     * @return list<string>
     */
    public function scopes(): array;

    public function findContactIdsByPhone(?string $phone): BitrixEntityMatch;

    public function findContactIdsByName(?string $name): BitrixEntityMatch;

    public function findContactIdsByChildName(?string $childName): BitrixEntityMatch;

    /**
     * @param  list<int>  $contactIds
     * @return list<string>
     */
    public function emailsForContactIds(array $contactIds, int $limit = 3): array;
}
