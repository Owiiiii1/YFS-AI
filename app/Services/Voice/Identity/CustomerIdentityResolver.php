<?php

namespace App\Services\Voice\Identity;

use App\Services\Jfs\JfsReadService;
use Illuminate\Support\Facades\Log;

class CustomerIdentityResolver
{
    public function __construct(
        private readonly JfsReadService $jfs,
    ) {}

    public function resolveByPhone(?string $phone): CustomerIdentityResult
    {
        return $this->fromClients($this->jfs->findClientsByPhoneDigits((string) $phone), 'phone');
    }

    public function resolveByName(?string $name): CustomerIdentityResult
    {
        return $this->fromClients($this->jfs->findClientsByName((string) $name), 'name');
    }

    public function resolveByChildName(?string $childName): CustomerIdentityResult
    {
        return $this->fromClients($this->jfs->findClientsByChildName((string) $childName), 'child_name');
    }

    /**
     * Native Agent tool input: spoken parent name plus optional child name.
     */
    public function resolveBySpokenHints(?string $name, ?string $childName = null): CustomerIdentityResult
    {
        $name = trim((string) $name);
        $childName = trim((string) $childName);

        if ($name === '' && $childName === '') {
            return CustomerIdentityResult::notFound('name');
        }

        if ($name !== '' && $childName === '') {
            return $this->resolveByName($name);
        }

        if ($name === '') {
            return $this->resolveByChildName($childName);
        }

        $byName = $this->jfs->findClientsByName($name);
        if ($this->jfs->lastReadFailed()) {
            $this->logStatus(CustomerIdentityResult::unavailable('name'));

            return CustomerIdentityResult::unavailable('name');
        }

        $byChild = $this->jfs->findClientsByChildName($childName);
        if ($this->jfs->lastReadFailed()) {
            $this->logStatus(CustomerIdentityResult::unavailable('child_name'));

            return CustomerIdentityResult::unavailable('child_name');
        }

        $childIds = [];
        foreach ($byChild as $row) {
            $childIds[(int) $row['id']] = $row;
        }

        $intersected = [];
        foreach ($byName as $row) {
            $id = (int) $row['id'];
            if (isset($childIds[$id])) {
                $intersected[] = $row;
            }
        }

        return $this->fromLoaded($intersected, 'name_and_child');
    }

    /**
     * @param  list<array{id:int,name:?string,language:?string}>  $clients
     */
    private function fromClients(array $clients, string $method): CustomerIdentityResult
    {
        if ($this->jfs->lastReadFailed()) {
            $result = CustomerIdentityResult::unavailable($method);
            $this->logStatus($result);

            return $result;
        }

        return $this->fromLoaded($clients, $method);
    }

    /**
     * @param  list<array{id:int,name:?string,language:?string}>  $clients
     */
    private function fromLoaded(array $clients, string $method): CustomerIdentityResult
    {
        $unique = [];
        foreach ($clients as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $unique[$id] = $row;
        }

        $count = count($unique);
        $result = match (true) {
            $count === 0 => CustomerIdentityResult::notFound($method),
            $count > 1 => CustomerIdentityResult::ambiguous($method, $count),
            default => $this->uniqueFromRow(array_values($unique)[0], $method),
        };

        $this->logStatus($result);

        return $result;
    }

    /**
     * @param  array{id?:int,name?:?string,language?:?string}  $row
     */
    private function uniqueFromRow(array $row, string $method): CustomerIdentityResult
    {
        $name = isset($row['name']) && is_string($row['name']) ? trim($row['name']) : null;

        return CustomerIdentityResult::unique(
            $method,
            (int) $row['id'],
            $name !== '' ? $name : null,
            isset($row['language']) && is_string($row['language']) ? trim($row['language']) : null,
        );
    }

    private function logStatus(CustomerIdentityResult $result): void
    {
        Log::info('voice.identity.resolved', [
            'status' => $result->status,
            'match_method' => $result->matchMethod,
            'match_count' => $result->matchCount,
        ]);
    }
}
