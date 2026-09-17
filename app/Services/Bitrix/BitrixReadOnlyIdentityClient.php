<?php

namespace App\Services\Bitrix;

use App\Services\Identity\IdentityNameMatcher;
use App\Services\Jfs\JfsIdentityMatch;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class BitrixReadOnlyIdentityClient implements BitrixIdentityGateway
{
    private const ALLOWED_METHODS = [
        'scope',
        'profile',
        'crm.duplicate.findbycomm',
        'telephony.externalCall.searchCrmEntities',
        'crm.contact.list',
        'crm.contact.get',
    ];

    public const CHILD_NAME_FIELD = 'UF_CRM_1748955762209';

    public function isConfigured(): bool
    {
        return $this->webhookBase() !== '';
    }

    public function scopes(): array
    {
        $response = $this->call('scope', []);
        $result = $response['result'] ?? null;
        if (! is_array($result)) {
            return [];
        }

        $scopes = [];
        foreach ($result as $scope) {
            if (is_string($scope) && $scope !== '') {
                $scopes[] = $scope;
            }
        }

        return array_values(array_unique($scopes));
    }

    public function findContactIdsByPhone(?string $phone): BitrixEntityMatch
    {
        $started = hrtime(true);
        $values = $this->phoneSearchValues($phone);
        if ($values === []) {
            return BitrixEntityMatch::notFound($this->ms($started));
        }
        if (! $this->isConfigured()) {
            return BitrixEntityMatch::unavailable($this->ms($started));
        }

        $duplicate = $this->call('crm.duplicate.findbycomm', [
            'entity_type' => 'CONTACT',
            'type' => 'PHONE',
            'values' => $values,
        ]);
        if (! $duplicate['ok']) {
            return BitrixEntityMatch::unavailable($this->ms($started));
        }

        $ids = $this->contactIdsFromDuplicate($duplicate['result']);
        if ($ids !== []) {
            return $this->matchFromIds($ids, $this->ms($started));
        }

        $telephony = $this->call('telephony.externalCall.searchCrmEntities', [
            'PHONE_NUMBER' => $values[0],
        ]);
        if (! $telephony['ok']) {
            return BitrixEntityMatch::notFound($this->ms($started));
        }

        return $this->matchFromIds(
            $this->contactIdsFromTelephony($telephony['result']),
            $this->ms($started),
        );
    }

    public function findContactIdsByName(?string $name): BitrixEntityMatch
    {
        $started = hrtime(true);
        $name = trim((string) $name);
        if ($name === '' || mb_strlen($name) < 2) {
            return BitrixEntityMatch::notFound($this->ms($started));
        }
        if (! $this->isConfigured()) {
            return BitrixEntityMatch::unavailable($this->ms($started));
        }

        $match = $this->searchContactsByNameString($name, $started);
        if ($match->status !== BitrixEntityMatch::NOT_FOUND) {
            return $match;
        }

        $latin = IdentityNameMatcher::primaryLatin($name);
        if ($latin === '' || mb_strtolower($latin) === mb_strtolower($name)) {
            return $match;
        }

        return $this->searchContactsByNameString($latin, $started);
    }

    public function findContactIdsByChildName(?string $childName): BitrixEntityMatch
    {
        $started = hrtime(true);
        $childName = trim((string) $childName);
        if ($childName === '' || mb_strlen($childName) < 2) {
            return BitrixEntityMatch::notFound($this->ms($started));
        }
        if (! $this->isConfigured()) {
            return BitrixEntityMatch::unavailable($this->ms($started));
        }

        $response = $this->call('crm.contact.list', [
            'select' => ['ID'],
            'filter' => ['%'.self::CHILD_NAME_FIELD => $childName],
            'start' => 0,
        ]);
        if (! $response['ok']) {
            return BitrixEntityMatch::unavailable($this->ms($started));
        }

        $total = (int) ($response['total'] ?? 0);
        if ($total > 8) {
            return BitrixEntityMatch::ambiguous([], $this->ms($started), $total);
        }

        $match = $this->matchFromIds(
            $this->idsFromList($response['result'], $total),
            $this->ms($started),
        );
        if ($match->status !== BitrixEntityMatch::NOT_FOUND) {
            return $match;
        }

        $latin = IdentityNameMatcher::primaryLatin($childName);
        if ($latin === '' || mb_strtolower($latin) === mb_strtolower($childName)) {
            return $match;
        }

        $latinResponse = $this->call('crm.contact.list', [
            'select' => ['ID'],
            'filter' => ['%'.self::CHILD_NAME_FIELD => $latin],
            'start' => 0,
        ]);
        if (! $latinResponse['ok']) {
            return $match;
        }
        $latinTotal = (int) ($latinResponse['total'] ?? 0);
        if ($latinTotal > 8) {
            return BitrixEntityMatch::ambiguous([], $this->ms($started), $latinTotal);
        }

        return $this->matchFromIds(
            $this->idsFromList($latinResponse['result'], $latinTotal),
            $this->ms($started),
        );
    }

    public function emailsForContactIds(array $contactIds, int $limit = 3): array
    {
        if (! $this->isConfigured()) {
            return [];
        }

        $emails = [];
        $seen = [];
        foreach (array_slice(array_values(array_unique(array_map('intval', $contactIds))), 0, max(1, $limit)) as $id) {
            if ($id <= 0) {
                continue;
            }
            $response = $this->call('crm.contact.get', [
                'id' => $id,
                'select' => ['ID', 'EMAIL'],
            ]);
            if (! $response['ok'] || ! is_array($response['result'])) {
                continue;
            }
            foreach ($this->emailsFromContact($response['result']) as $email) {
                $key = mb_strtolower($email);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $emails[] = $email;
            }
        }

        return $emails;
    }

    private function searchContactsByNameString(string $name, int $started): BitrixEntityMatch
    {
        $parts = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY);
        if (! is_array($parts)) {
            $parts = [];
        }

        $full = $this->call('crm.contact.list', [
            'select' => ['ID'],
            'filter' => ['%NAME' => $name],
            'start' => 0,
        ]);
        if (! $full['ok']) {
            return BitrixEntityMatch::unavailable($this->ms($started));
        }
        $total = (int) ($full['total'] ?? 0);
        if ($total > 8) {
            return BitrixEntityMatch::ambiguous([], $this->ms($started), $total);
        }
        $ids = $this->idsFromList($full['result'], $total);
        if ($total === 1 && count($ids) === 1) {
            return BitrixEntityMatch::unique($ids, $this->ms($started));
        }
        if (count($parts) >= 2) {
            $last = $parts[count($parts) - 1];
            $first = implode(' ', array_slice($parts, 0, -1));
            $split = $this->call('crm.contact.list', [
                'select' => ['ID'],
                'filter' => [
                    '%NAME' => $first,
                    '%LAST_NAME' => $last,
                ],
                'start' => 0,
            ]);
            if ($split['ok']) {
                $splitTotal = (int) ($split['total'] ?? 0);
                if ($splitTotal > 8) {
                    return BitrixEntityMatch::ambiguous([], $this->ms($started), $splitTotal);
                }
                $ids = array_merge($ids, $this->idsFromList($split['result'], $splitTotal));
            }
        }

        return $this->matchFromIds($ids, $this->ms($started));
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{ok: bool, result: mixed, total: mixed, error: ?string, ms: int}
     */
    private function call(string $method, array $params): array
    {
        $started = hrtime(true);
        if (! in_array($method, self::ALLOWED_METHODS, true)) {
            Log::warning('bitrix.rest.method_blocked', ['method' => $method]);

            return ['ok' => false, 'result' => null, 'total' => null, 'error' => 'method_blocked', 'ms' => $this->ms($started)];
        }

        $base = $this->webhookBase();
        if ($base === '') {
            return ['ok' => false, 'result' => null, 'total' => null, 'error' => 'not_configured', 'ms' => $this->ms($started)];
        }

        try {
            $http = Http::timeout($this->timeoutSeconds())
                ->connectTimeout(2)
                ->acceptJson()
                ->asJson()
                ->post($base.'/'.$method.'.json', $params);
        } catch (ConnectionException $exception) {
            Log::warning('bitrix.rest.timeout', ['method' => $method]);

            return ['ok' => false, 'result' => null, 'total' => null, 'error' => 'timeout', 'ms' => $this->ms($started)];
        } catch (Throwable $exception) {
            Log::warning('bitrix.rest.http_error', ['method' => $method]);

            return ['ok' => false, 'result' => null, 'total' => null, 'error' => 'http_error', 'ms' => $this->ms($started)];
        }

        $ms = $this->ms($started);
        if ($http->status() === 429) {
            Log::warning('bitrix.rest.rate_limited', ['method' => $method, 'ms' => $ms]);

            return ['ok' => false, 'result' => null, 'total' => null, 'error' => 'rate_limit', 'ms' => $ms];
        }

        $json = $http->json();
        if (! is_array($json)) {
            Log::warning('bitrix.rest.malformed', ['method' => $method, 'http_status' => $http->status(), 'ms' => $ms]);

            return ['ok' => false, 'result' => null, 'total' => null, 'error' => 'malformed', 'ms' => $ms];
        }

        $error = $json['error'] ?? null;
        if (is_string($error) && $error !== '') {
            $code = $this->sanitizeErrorCode($error);
            Log::warning('bitrix.rest.error', [
                'method' => $method,
                'error' => $code,
                'http_status' => $http->status(),
                'ms' => $ms,
            ]);

            return ['ok' => false, 'result' => null, 'total' => null, 'error' => $code, 'ms' => $ms];
        }

        if (! $http->successful()) {
            Log::warning('bitrix.rest.http_status', ['method' => $method, 'http_status' => $http->status(), 'ms' => $ms]);

            return ['ok' => false, 'result' => null, 'total' => null, 'error' => 'http_'.$http->status(), 'ms' => $ms];
        }

        return [
            'ok' => true,
            'result' => $json['result'] ?? null,
            'total' => $json['total'] ?? null,
            'error' => null,
            'ms' => $ms,
        ];
    }

    private function webhookBase(): string
    {
        $raw = trim((string) config('services.bitrix.webhook_url', ''));
        if ($raw === '') {
            return '';
        }

        $raw = rtrim($raw, '/');
        $raw = (string) preg_replace('#/(profile|scope)\.json$#i', '', $raw);

        return rtrim($raw, '/');
    }

    private function timeoutSeconds(): int
    {
        $timeout = (int) config('services.bitrix.timeout_seconds', 3);

        return max(1, min(8, $timeout));
    }

    /**
     * @return list<string>
     */
    private function phoneSearchValues(?string $phone): array
    {
        $keys = JfsIdentityMatch::phoneDigitKeys((string) $phone);
        $values = [];
        foreach ($keys as $digits) {
            $values[] = $digits;
            $values[] = '+'.$digits;
        }

        return array_values(array_unique($values));
    }

    /**
     * @return list<int>
     */
    private function contactIdsFromDuplicate(mixed $result): array
    {
        if (is_array($result) && isset($result['CONTACT']) && is_array($result['CONTACT'])) {
            return $this->intIds($result['CONTACT']);
        }

        if (is_array($result) && array_is_list($result)) {
            return $this->intIds(is_array($result) ? $result : []);
        }

        return [];
    }

    /**
     * @return list<int>
     */
    private function contactIdsFromTelephony(mixed $result): array
    {
        if (! is_array($result)) {
            return [];
        }

        $ids = [];
        foreach ($result as $row) {
            if (! is_array($row)) {
                continue;
            }
            $type = strtoupper((string) ($row['CRM_ENTITY_TYPE'] ?? ''));
            if ($type !== '' && $type !== 'CONTACT') {
                continue;
            }
            $id = (int) ($row['CRM_ENTITY_ID'] ?? $row['ID'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return list<int>
     */
    private function idsFromList(mixed $result, int $total): array
    {
        if ($total > 8) {
            return [];
        }
        if (! is_array($result)) {
            return [];
        }

        $ids = [];
        foreach ($result as $row) {
            if (is_array($row)) {
                $id = (int) ($row['ID'] ?? 0);
            } else {
                $id = (int) $row;
            }
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  list<int|string>  $values
     * @return list<int>
     */
    private function intIds(array $values): array
    {
        $ids = [];
        foreach ($values as $value) {
            $id = (int) $value;
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  list<int>  $ids
     */
    private function matchFromIds(array $ids, int $elapsedMs): BitrixEntityMatch
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn (int $id): bool => $id > 0)));
        $count = count($ids);
        if ($count === 0) {
            return BitrixEntityMatch::notFound($elapsedMs);
        }
        if ($count === 1) {
            return BitrixEntityMatch::unique($ids, $elapsedMs);
        }

        return BitrixEntityMatch::ambiguous(array_slice($ids, 0, 8), $elapsedMs);
    }

    /**
     * @param  array<string, mixed>  $contact
     * @return list<string>
     */
    private function emailsFromContact(array $contact): array
    {
        $raw = $contact['EMAIL'] ?? null;
        if (! is_array($raw)) {
            return [];
        }

        $emails = [];
        foreach ($raw as $item) {
            $value = is_array($item) ? ($item['VALUE'] ?? null) : $item;
            if (! is_string($value)) {
                continue;
            }
            $email = mb_strtolower(trim($value));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $emails[] = $email;
            }
        }

        return array_values(array_unique($emails));
    }

    private function sanitizeErrorCode(string $error): string
    {
        $error = strtolower(trim($error));
        $error = preg_replace('/[^a-z0-9_.-]/', '', $error) ?? 'error';

        return $error === '' ? 'error' : substr($error, 0, 80);
    }

    private function ms(int $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
