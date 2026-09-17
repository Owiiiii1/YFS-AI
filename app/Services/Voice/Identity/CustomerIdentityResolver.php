<?php

namespace App\Services\Voice\Identity;

use App\Services\Bitrix\BitrixEntityMatch;
use App\Services\Bitrix\BitrixIdentityGateway;
use App\Services\Bitrix\BitrixYfsLinker;
use App\Services\Jfs\JfsReadService;
use Illuminate\Support\Facades\Log;

class CustomerIdentityResolver
{
    public function __construct(
        private readonly JfsReadService $jfs,
        private readonly ?BitrixIdentityGateway $bitrix = null,
        private readonly ?BitrixYfsLinker $linker = null,
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
     * Optional $phone is a trusted VoiceContact number, never LLM input.
     */
    public function resolveBySpokenHints(?string $name, ?string $childName = null, ?string $phone = null): CustomerIdentityResult
    {
        $name = trim((string) $name);
        $childName = trim((string) $childName);
        $phone = trim((string) $phone);

        if ($name === '' && $childName === '') {
            return CustomerIdentityResult::notFound('name');
        }

        $phoneClients = [];
        if ($phone !== '') {
            $phoneClients = $this->jfs->findClientsByPhoneDigits($phone);
            if ($this->jfs->lastReadFailed()) {
                $phoneClients = [];
            }
        }

        $namePool = [];
        $nameExact = false;
        $nameSearched = $name !== '';
        if ($nameSearched) {
            $loaded = $this->loadNamePool($name);
            if ($loaded === null) {
                return CustomerIdentityResult::unavailable('name');
            }
            [$namePool, $nameExact] = $loaded;
        }

        $childPool = [];
        $childExact = false;
        $childSearched = $childName !== '';
        if ($childSearched) {
            $loaded = $this->loadChildPool($childName);
            if ($loaded === null) {
                return CustomerIdentityResult::unavailable('child_name');
            }
            [$childPool, $childExact] = $loaded;
        }

        if ($nameSearched && $childSearched) {
            return $this->finalizeSpoken(
                $this->intersectById($namePool, $childPool),
                'name_and_child',
                $namePool !== [] && $childPool !== [],
                $phoneClients,
            );
        }

        if ($nameSearched) {
            return $this->finalizeSpoken(
                $namePool,
                $nameExact ? 'name' : 'name_variant',
                $nameExact,
                $phoneClients,
            );
        }

        return $this->finalizeSpoken(
            $childPool,
            $childExact ? 'child_name' : 'child_name_variant',
            $childExact,
            $phoneClients,
        );
    }

    public function resolveByPhoneFast(?string $phone, ?int $budgetMs = null): CustomerIdentityResult
    {
        $started = hrtime(true);
        $yfs = $this->resolveByPhone($phone);
        if ($yfs->isUnique() || $yfs->status === CustomerIdentityResult::SOURCE_UNAVAILABLE) {
            return $yfs;
        }
        if ($yfs->status === CustomerIdentityResult::AMBIGUOUS) {
            return $yfs;
        }

        $linked = $this->bitrixPhoneToYfs($phone, $this->remaining($started, $budgetMs ?? $this->fastBudgetMs()));
        $this->logTiming('phone', $yfs, $linked, $started);

        return $linked ?? $yfs;
    }

    public function resolveBySpokenHintsFast(?string $name, ?string $childName = null, ?int $budgetMs = null, ?string $phone = null): CustomerIdentityResult
    {
        $started = hrtime(true);
        $yfs = $this->resolveBySpokenHints($name, $childName, $phone);
        if ($yfs->isUnique() || $yfs->status === CustomerIdentityResult::SOURCE_UNAVAILABLE) {
            return $yfs;
        }

        $budget = $this->remaining($started, $budgetMs ?? $this->fastToolBudgetMs());
        $linked = $this->bitrixNameToYfs($name, $childName, $budget);
        $this->logTiming('name', $yfs, $linked, $started);

        return $linked ?? $yfs;
    }

    private function bitrixPhoneToYfs(?string $phone, int $budgetMs): ?CustomerIdentityResult
    {
        if ($budgetMs < 200 || $this->bitrix === null || $this->linker === null || ! $this->bitrix->isConfigured()) {
            return null;
        }

        $match = $this->bitrix->findContactIdsByPhone($phone);
        Log::info('bitrix.identity.phone_lookup', [
            'status' => $match->status,
            'match_count' => $match->matchCount,
            'phone_lookup_ms' => $match->elapsedMs,
        ]);
        if ($match->status === BitrixEntityMatch::UNAVAILABLE) {
            return null;
        }
        if (! $match->isUnique()) {
            return $match->status === BitrixEntityMatch::AMBIGUOUS
                ? CustomerIdentityResult::ambiguous('bitrix_phone_email', $match->matchCount)
                : null;
        }

        $emails = $this->bitrix->emailsForContactIds($match->contactIds, 3);
        if ($emails === []) {
            return CustomerIdentityResult::notFound('bitrix_phone_email');
        }

        $linked = $this->linker->resolveFromEmails($emails, 'bitrix_phone_email');
        if ($linked->status === CustomerIdentityResult::SOURCE_UNAVAILABLE) {
            return null;
        }

        return $linked;
    }

    private function bitrixNameToYfs(?string $name, ?string $childName, int $budgetMs): ?CustomerIdentityResult
    {
        if ($budgetMs < 200 || $this->bitrix === null || $this->linker === null || ! $this->bitrix->isConfigured()) {
            return null;
        }

        $match = $this->bitrix->findContactIdsByName($name);
        Log::info('bitrix.identity.name_lookup', [
            'status' => $match->status,
            'match_count' => $match->matchCount,
            'name_lookup_ms' => $match->elapsedMs,
        ]);

        if ($match->status === BitrixEntityMatch::UNAVAILABLE) {
            return null;
        }

        $ids = $match->contactIds;
        if ($childName !== null && trim($childName) !== '' && $budgetMs > 400) {
            $childMatch = $this->bitrix->findContactIdsByChildName($childName);
            if ($childMatch->isUnique() || $childMatch->status === BitrixEntityMatch::AMBIGUOUS) {
                $childIds = array_flip($childMatch->contactIds);
                $intersected = [];
                foreach ($ids as $id) {
                    if (isset($childIds[$id])) {
                        $intersected[] = $id;
                    }
                }
                if ($intersected !== []) {
                    $ids = $intersected;
                    $match = count($ids) === 1
                        ? BitrixEntityMatch::unique($ids, $match->elapsedMs)
                        : BitrixEntityMatch::ambiguous($ids, $match->elapsedMs);
                }
            }
        }

        if ($match->status === BitrixEntityMatch::NOT_FOUND || $ids === []) {
            return null;
        }
        if (count($ids) !== 1) {
            return CustomerIdentityResult::ambiguous('bitrix_name_email', max($match->matchCount, count($ids)));
        }

        $emails = $this->bitrix->emailsForContactIds($ids, 3);
        if ($emails === []) {
            return null;
        }

        $linked = $this->linker->resolveFromEmails($emails, 'bitrix_name_email');
        if ($linked->status === CustomerIdentityResult::SOURCE_UNAVAILABLE) {
            return null;
        }

        return $linked;
    }

    /**
     * @return array{0: list<array{id:int,name:?string,language:?string}>, 1: bool}|null
     */
    private function loadNamePool(string $name): ?array
    {
        $exact = $this->jfs->findClientsByName($name);
        if ($this->jfs->lastReadFailed()) {
            $this->logStatus(CustomerIdentityResult::unavailable('name'));

            return null;
        }
        if ($exact !== []) {
            return [$exact, true];
        }

        $variants = $this->jfs->findClientsByNameVariants($name);
        if ($this->jfs->lastReadFailed()) {
            $this->logStatus(CustomerIdentityResult::unavailable('name'));

            return null;
        }

        return [$variants, false];
    }

    /**
     * @return array{0: list<array{id:int,name:?string,language:?string}>, 1: bool}|null
     */
    private function loadChildPool(string $childName): ?array
    {
        $exact = $this->jfs->findClientsByChildName($childName);
        if ($this->jfs->lastReadFailed()) {
            $this->logStatus(CustomerIdentityResult::unavailable('child_name'));

            return null;
        }
        if ($exact !== []) {
            return [$exact, true];
        }

        $variants = $this->jfs->findClientsByChildNameVariants($childName);
        if ($this->jfs->lastReadFailed()) {
            $this->logStatus(CustomerIdentityResult::unavailable('child_name'));

            return null;
        }

        return [$variants, false];
    }

    /**
     * @param  list<array{id:int,name:?string,language:?string}>  $pool
     * @param  list<array{id:int,name:?string,language:?string}>  $phoneClients
     */
    private function finalizeSpoken(array $pool, string $method, bool $allowUnique, array $phoneClients): CustomerIdentityResult
    {
        if ($phoneClients !== []) {
            $withPhone = $this->intersectById($pool, $phoneClients);
            $phoneCount = count($this->indexById($withPhone));
            if ($phoneCount === 1) {
                $phoneMethod = match (true) {
                    $method === 'name_and_child' => 'name_and_child_and_phone',
                    str_starts_with($method, 'child_') => 'child_name_and_phone',
                    default => 'name_and_phone',
                };

                return $this->fromLoaded($withPhone, $phoneMethod);
            }
            if ($phoneCount > 1) {
                return $this->fromLoaded($withPhone, $method);
            }
        }

        if (! $allowUnique) {
            $count = count($this->indexById($pool));
            if ($count === 0) {
                return $this->fromLoaded([], $method);
            }
            if ($count === 1) {
                $result = CustomerIdentityResult::ambiguous($method, 1);
                $this->logStatus($result);

                return $result;
            }

            return $this->fromLoaded($pool, $method);
        }

        return $this->fromLoaded($pool, $method);
    }

    /**
     * @param  list<array{id:int,name:?string,language:?string}>  $left
     * @param  list<array{id:int,name:?string,language:?string}>  $right
     * @return list<array{id:int,name:?string,language:?string}>
     */
    private function intersectById(array $left, array $right): array
    {
        $keep = $this->indexById($right);
        $intersected = [];
        foreach ($left as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0 && isset($keep[$id])) {
                $intersected[] = $row;
            }
        }

        return $intersected;
    }

    /**
     * @param  list<array{id:int,name:?string,language:?string}>  $rows
     * @return array<int, array{id:int,name:?string,language:?string}>
     */
    private function indexById(array $rows): array
    {
        $unique = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $unique[$id] = $row;
            }
        }

        return $unique;
    }

    private function remaining(int $started, int $budgetMs): int
    {
        $used = (int) round((hrtime(true) - $started) / 1_000_000);

        return max(0, $budgetMs - $used);
    }

    private function fastBudgetMs(): int
    {
        return max(400, (int) config('services.bitrix.fast_budget_ms', 1500));
    }

    private function fastToolBudgetMs(): int
    {
        return max(800, (int) config('services.bitrix.fast_tool_budget_ms', 4000));
    }

    private function logTiming(
        string $kind,
        CustomerIdentityResult $yfs,
        ?CustomerIdentityResult $linked,
        int $started,
    ): void {
        Log::info('bitrix.identity.total', [
            'kind' => $kind,
            'yfs_status' => $yfs->status,
            'final_status' => ($linked ?? $yfs)->status,
            'match_method' => ($linked ?? $yfs)->matchMethod,
            'total_ms' => (int) round((hrtime(true) - $started) / 1_000_000),
            'used_bitrix' => $linked !== null,
        ]);
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
