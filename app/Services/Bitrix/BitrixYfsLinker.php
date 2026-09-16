<?php

namespace App\Services\Bitrix;

use App\Services\Jfs\JfsReadService;
use App\Services\Voice\Identity\CustomerIdentityResult;
use Illuminate\Support\Facades\Log;

final class BitrixYfsLinker
{
    public function __construct(
        private readonly JfsReadService $jfs,
    ) {}

    /**
     * @param  list<string>  $emails
     */
    public function resolveFromEmails(array $emails, string $matchMethod): CustomerIdentityResult
    {
        $started = hrtime(true);
        $byId = [];

        foreach ($emails as $email) {
            if (! is_string($email)) {
                continue;
            }
            $lookup = $this->jfs->findClientByEmail($email);
            if (($lookup['status'] ?? '') === 'unavailable' || $this->jfs->lastReadFailed()) {
                $this->logTiming($matchMethod, CustomerIdentityResult::SOURCE_UNAVAILABLE, 0, $started);

                return CustomerIdentityResult::unavailable($matchMethod);
            }
            if (($lookup['status'] ?? '') === 'ambiguous') {
                $this->logTiming($matchMethod, CustomerIdentityResult::AMBIGUOUS, 2, $started);

                return CustomerIdentityResult::ambiguous($matchMethod, 2);
            }
            if (($lookup['status'] ?? '') !== 'found' || ! is_array($lookup['client'] ?? null)) {
                continue;
            }

            $client = $lookup['client'];
            if (($client['role'] ?? 'client') !== 'client') {
                continue;
            }
            if (($client['status'] ?? 'active') === 'blocked') {
                continue;
            }
            $id = (int) ($client['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $byId[$id] = $client;
        }

        $count = count($byId);
        if ($count === 0) {
            $this->logTiming($matchMethod, CustomerIdentityResult::NOT_FOUND, 0, $started);

            return CustomerIdentityResult::notFound($matchMethod);
        }
        if ($count > 1) {
            $this->logTiming($matchMethod, CustomerIdentityResult::AMBIGUOUS, $count, $started);

            return CustomerIdentityResult::ambiguous($matchMethod, $count);
        }

        $row = array_values($byId)[0];
        $name = isset($row['name']) && is_string($row['name']) ? trim($row['name']) : null;
        $result = CustomerIdentityResult::unique(
            $matchMethod,
            (int) $row['id'],
            $name !== '' ? $name : null,
            null,
        );
        $this->logTiming($matchMethod, $result->status, 1, $started);

        return $result;
    }

    private function logTiming(string $matchMethod, string $status, int $matchCount, int $started): void
    {
        Log::info('bitrix.identity.yfs_link', [
            'match_method' => $matchMethod,
            'status' => $status,
            'match_count' => $matchCount,
            'yfs_link_ms' => (int) round((hrtime(true) - $started) / 1_000_000),
        ]);
    }
}
