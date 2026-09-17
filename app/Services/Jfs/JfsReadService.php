<?php

namespace App\Services\Jfs;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class JfsReadService
{
    private bool $lastReadFailed = false;

    public function isConfigured(): bool
    {
        return filled(config('database.connections.jfs.database'))
            && filled(config('database.connections.jfs.username'));
    }

    /**
     * True when the last JFS read could not complete (unconfigured or query
     * exception). Empty result sets are not a failure.
     */
    public function lastReadFailed(): bool
    {
        return $this->lastReadFailed;
    }

    /**
     * @return list<array{name:string,city:string,location:string,starts_at:?string,ends_at:?string,date_announced:bool,is_past:bool,description:?string}>
     */
    public function publicEvents(): array
    {
        $this->lastReadFailed = false;

        if (! $this->isConfigured()) {
            $this->lastReadFailed = true;

            return [];
        }

        try {
            $rows = DB::connection('jfs')
                ->table('events as e')
                ->leftJoin('event_infos as i', 'i.event_id', '=', 'e.id')
                ->orderBy('e.starts_at')
                ->get([
                    'e.name',
                    'e.city',
                    'e.location',
                    'e.starts_at',
                    'e.ends_at',
                    'e.client_show_event_date',
                    'i.description_i18n',
                ]);
        } catch (Throwable $exception) {
            $this->lastReadFailed = true;
            Log::warning('JFS event read failed.', ['message' => $exception->getMessage()]);

            return [];
        }

        return $rows->map(function ($row): array {
            $startsAt = $row->starts_at ? (string) $row->starts_at : null;
            // The main project decides whether a date may be shown to clients at all.
            $announced = $this->dateIsAnnounced($row->client_show_event_date ?? null);

            return [
                'name' => (string) $row->name,
                'city' => (string) $row->city,
                'location' => (string) $row->location,
                'starts_at' => $announced ? $startsAt : null,
                'ends_at' => $announced && $row->ends_at ? (string) $row->ends_at : null,
                'date_announced' => $announced,
                'is_past' => $this->isPast($startsAt),
                'description' => $this->localizedText($row->description_i18n ?? null),
            ];
        })->all();
    }

    private function dateIsAnnounced(mixed $flag): bool
    {
        return $flag === null || (bool) $flag;
    }

    private function isPast(?string $startsAt): bool
    {
        return $startsAt !== null && CarbonImmutable::parse($startsAt)->lt(CarbonImmutable::now()->startOfDay());
    }

    private function formatDate(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $date = CarbonImmutable::parse($value);

        return $date->format('H:i') === '00:00' ? $date->toDateString() : $date->format('Y-m-d H:i');
    }

    /**
     * @return array{status:string,client:?array{id:int,name:?string,email:string,phone:?string,role:string},children:list<array{first_name:string,last_name:string,birthdate:?string}>}
     */
    public function findClientByEmail(string $email): array
    {
        $email = mb_strtolower(trim($email));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['status' => 'invalid', 'client' => null, 'children' => []];
        }

        $this->lastReadFailed = false;

        if (! $this->isConfigured()) {
            $this->lastReadFailed = true;

            return ['status' => 'unavailable', 'client' => null, 'children' => []];
        }

        try {
            $matches = DB::connection('jfs')
                ->table('app_users')
                ->whereRaw('LOWER(email) = ?', [$email])
                ->get(['id', 'name', 'email', 'phone', 'role', 'status']);
        } catch (Throwable $exception) {
            $this->lastReadFailed = true;
            Log::warning('JFS client lookup failed.', ['message' => $exception->getMessage()]);

            return ['status' => 'unavailable', 'client' => null, 'children' => []];
        }

        if ($matches->count() === 0) {
            return ['status' => 'not_found', 'client' => null, 'children' => []];
        }

        if ($matches->count() > 1) {
            return ['status' => 'ambiguous', 'client' => null, 'children' => []];
        }

        $user = $matches->first();
        $children = [];
        try {
            $children = DB::connection('jfs')
                ->table('children')
                ->where('client_app_user_id', $user->id)
                ->orderBy('id')
                ->get(['first_name', 'birthdate'])
                ->map(static fn ($child): array => [
                    'first_name' => (string) $child->first_name,
                    'last_name' => '',
                    'birthdate' => $child->birthdate ? (string) $child->birthdate : null,
                ])
                ->all();
        } catch (Throwable $exception) {
            Log::warning('JFS children lookup failed.', ['message' => $exception->getMessage()]);
        }

        return [
            'status' => 'found',
            'client' => [
                'id' => (int) $user->id,
                'name' => filled($user->name) ? (string) $user->name : null,
                'email' => (string) $user->email,
                'phone' => filled($user->phone) ? (string) $user->phone : null,
                'role' => (string) $user->role,
            ],
            'children' => $children,
        ];
    }

    /**
     * Voice identity lookup. Returns client id/name/language only.
     *
     * @return list<array{id:int,name:?string,language:?string}>
     */
    public function findClientsByPhoneDigits(string $phone): array
    {
        $this->lastReadFailed = false;
        $keys = JfsIdentityMatch::phoneDigitKeys($phone);
        if ($keys === []) {
            return [];
        }

        if (! $this->isConfigured()) {
            $this->lastReadFailed = true;

            return [];
        }

        try {
            $rows = $this->clientIdentityQuery()->get(['id', 'name', 'language', 'phone']);
        } catch (Throwable) {
            $this->lastReadFailed = true;
            Log::warning('JFS identity phone read failed.');

            return [];
        }

        $matches = [];
        foreach ($rows as $row) {
            if (! JfsIdentityMatch::phonesMatch((string) ($row->phone ?? ''), $phone)) {
                continue;
            }
            $matches[] = $this->identityRecord($row);
        }

        return $this->uniqueIdentityRecords($matches);
    }

    /**
     * @return list<array{id:int,name:?string,language:?string}>
     */
    public function findClientsByName(string $name): array
    {
        $this->lastReadFailed = false;
        if (JfsIdentityMatch::words($name) === []) {
            return [];
        }

        if (! $this->isConfigured()) {
            $this->lastReadFailed = true;

            return [];
        }

        try {
            $rows = $this->clientIdentityQuery()->get(['id', 'name', 'language']);
        } catch (Throwable) {
            $this->lastReadFailed = true;
            Log::warning('JFS identity name read failed.');

            return [];
        }

        $matches = [];
        foreach ($rows as $row) {
            if (! JfsIdentityMatch::nameMatches((string) ($row->name ?? ''), $name)) {
                continue;
            }
            $matches[] = $this->identityRecord($row);
        }

        return $this->uniqueIdentityRecords($matches);
    }

    /**
     * Transliteration / alias candidates. Exact whole-word hits are excluded
     * so callers can treat this pool as variant-only evidence.
     *
     * @return list<array{id:int,name:?string,language:?string}>
     */
    public function findClientsByNameVariants(string $name): array
    {
        $this->lastReadFailed = false;
        if (JfsIdentityMatch::words($name) === []) {
            return [];
        }

        if (! $this->isConfigured()) {
            $this->lastReadFailed = true;

            return [];
        }

        try {
            $rows = $this->clientIdentityQuery()->get(['id', 'name', 'language']);
        } catch (Throwable) {
            $this->lastReadFailed = true;
            Log::warning('JFS identity name variant read failed.');

            return [];
        }

        $matches = [];
        foreach ($rows as $row) {
            $stored = (string) ($row->name ?? '');
            if (JfsIdentityMatch::nameMatches($stored, $name) || ! JfsIdentityMatch::nameMatchesVariant($stored, $name)) {
                continue;
            }
            $matches[] = $this->identityRecord($row);
        }

        return $this->uniqueIdentityRecords($matches);
    }

    /**
     * Parents linked to a matching child first_name. Child names are not returned.
     *
     * @return list<array{id:int,name:?string,language:?string}>
     */
    public function findClientsByChildName(string $childName): array
    {
        $this->lastReadFailed = false;
        if (JfsIdentityMatch::words($childName) === []) {
            return [];
        }

        if (! $this->isConfigured()) {
            $this->lastReadFailed = true;

            return [];
        }

        try {
            $rows = DB::connection('jfs')
                ->table('children as c')
                ->join('app_users as u', 'u.id', '=', 'c.client_app_user_id')
                ->where('u.role', 'client')
                ->where(function ($query): void {
                    $query->whereNull('u.status')->orWhere('u.status', '!=', 'blocked');
                })
                ->get(['u.id', 'u.name', 'u.language', 'c.first_name']);
        } catch (Throwable) {
            $this->lastReadFailed = true;
            Log::warning('JFS identity child read failed.');

            return [];
        }

        $matches = [];
        foreach ($rows as $row) {
            if (! JfsIdentityMatch::nameMatches((string) ($row->first_name ?? ''), $childName)) {
                continue;
            }
            $matches[] = $this->identityRecord($row);
        }

        return $this->uniqueIdentityRecords($matches);
    }

    /**
     * @return list<array{id:int,name:?string,language:?string}>
     */
    public function findClientsByChildNameVariants(string $childName): array
    {
        $this->lastReadFailed = false;
        if (JfsIdentityMatch::words($childName) === []) {
            return [];
        }

        if (! $this->isConfigured()) {
            $this->lastReadFailed = true;

            return [];
        }

        try {
            $rows = DB::connection('jfs')
                ->table('children as c')
                ->join('app_users as u', 'u.id', '=', 'c.client_app_user_id')
                ->where('u.role', 'client')
                ->where(function ($query): void {
                    $query->whereNull('u.status')->orWhere('u.status', '!=', 'blocked');
                })
                ->get(['u.id', 'u.name', 'u.language', 'c.first_name']);
        } catch (Throwable) {
            $this->lastReadFailed = true;
            Log::warning('JFS identity child variant read failed.');

            return [];
        }

        $matches = [];
        foreach ($rows as $row) {
            $stored = (string) ($row->first_name ?? '');
            if (JfsIdentityMatch::nameMatches($stored, $childName) || ! JfsIdentityMatch::nameMatchesVariant($stored, $childName)) {
                continue;
            }
            $matches[] = $this->identityRecord($row);
        }

        return $this->uniqueIdentityRecords($matches);
    }

    /**
     * Compact Voice customer-support snapshot. Read-only. No phones, emails,
     * payments, contracts, files, notes, or badge codes.
     *
     * @return array{
     *     customer: array{display_name:?string, language:?string},
     *     children: list<array{
     *         display_name: string,
     *         participations: list<array{
     *             show: string,
     *             city: string,
     *             date: ?string,
     *             date_announced: bool,
     *             is_past: bool,
     *             status: string,
     *             category?: string,
     *             package?: string
     *         }>
     *     }>
     * }|null
     */
    public function loadCustomerContext(int $appUserId): ?array
    {
        $this->lastReadFailed = false;
        if ($appUserId <= 0) {
            return null;
        }

        if (! $this->isConfigured()) {
            $this->lastReadFailed = true;

            return null;
        }

        try {
            $user = $this->clientIdentityQuery()
                ->where('id', $appUserId)
                ->first(['id', 'name', 'language']);
        } catch (Throwable) {
            $this->lastReadFailed = true;
            Log::warning('JFS customer context user read failed.');

            return null;
        }

        if ($user === null) {
            return null;
        }

        try {
            $childRows = DB::connection('jfs')
                ->table('children')
                ->where('client_app_user_id', $appUserId)
                ->orderBy('id')
                ->get(['id', 'first_name']);
        } catch (Throwable) {
            $this->lastReadFailed = true;
            Log::warning('JFS customer context children read failed.');

            return null;
        }

        $childIds = [];
        foreach ($childRows as $child) {
            $id = (int) $child->id;
            if ($id > 0) {
                $childIds[] = $id;
            }
        }

        $assignmentsByChild = [];
        if ($childIds !== []) {
            try {
                $assignmentRows = DB::connection('jfs')
                    ->table('child_event_assignments as a')
                    ->join('events as e', 'e.id', '=', 'a.event_id')
                    ->leftJoin('package_templates as p', 'p.id', '=', 'a.package_template_id')
                    ->whereIn('a.child_id', $childIds)
                    ->orderBy('e.starts_at')
                    ->orderBy('a.id')
                    ->get([
                        'a.child_id',
                        'a.status',
                        'a.family_look',
                        'e.name as event_name',
                        'e.city',
                        'e.starts_at',
                        'e.client_show_event_date',
                        'p.name as package_name',
                    ]);
            } catch (Throwable) {
                $this->lastReadFailed = true;
                Log::warning('JFS customer context assignment read failed.');

                return null;
            }

            foreach ($assignmentRows as $row) {
                $assignmentsByChild[(int) $row->child_id][] = $this->mapParticipation($row);
            }
            foreach ($assignmentsByChild as $childId => $list) {
                $assignmentsByChild[$childId] = $this->limitParticipationsForChild($list);
            }
            $assignmentsByChild = $this->capParticipationsAcrossChildren($assignmentsByChild);
        }

        $children = [];
        foreach ($childRows as $child) {
            $name = trim((string) ($child->first_name ?? ''));
            if ($name === '') {
                continue;
            }
            $children[] = [
                'display_name' => $name,
                'participations' => array_values($assignmentsByChild[(int) $child->id] ?? []),
            ];
        }

        $displayName = trim((string) ($user->name ?? ''));
        $language = trim((string) ($user->language ?? ''));

        return [
            'customer' => [
                'display_name' => $displayName !== '' ? $displayName : null,
                'language' => $language !== '' ? $language : null,
            ],
            'children' => $children,
        ];
    }

    /**
     * @return array{
     *     show: string,
     *     city: string,
     *     date: ?string,
     *     date_announced: bool,
     *     is_past: bool,
     *     status: string,
     *     category?: string,
     *     package?: string
     * }
     */
    private function mapParticipation(object $row): array
    {
        $startsAt = $row->starts_at ? (string) $row->starts_at : null;
        $announced = $this->dateIsAnnounced($row->client_show_event_date ?? null);
        $item = [
            'show' => (string) ($row->event_name ?? ''),
            'city' => (string) ($row->city ?? ''),
            'date' => $announced ? $this->formatDate($startsAt) : null,
            'date_announced' => $announced,
            'is_past' => $this->isPast($startsAt),
            'status' => (string) ($row->status ?? ''),
        ];
        if ((bool) ($row->family_look ?? false)) {
            $item['category'] = 'family_look';
        }
        $package = trim((string) ($row->package_name ?? ''));
        if ($package !== '') {
            $item['package'] = $package;
        }

        return $item;
    }

    /**
     * Upcoming/unannounced assignments are kept. Past shows are capped per child.
     *
     * @param  list<array{show:string,city:string,date:?string,date_announced:bool,is_past:bool,status:string,category?:string,package?:string}>  $list
     * @return list<array{show:string,city:string,date:?string,date_announced:bool,is_past:bool,status:string,category?:string,package?:string}>
     */
    private function limitParticipationsForChild(array $list): array
    {
        $upcoming = [];
        $past = [];
        foreach ($list as $item) {
            if ($item['is_past'] ?? false) {
                $past[] = $item;
            } else {
                $upcoming[] = $item;
            }
        }

        $past = array_reverse(array_slice($past, -8));

        return array_values(array_merge($upcoming, $past));
    }

    /**
     * @param  array<int, list<array{show:string,city:string,date:?string,date_announced:bool,is_past:bool,status:string,category?:string,package?:string}>>  $byChild
     * @return array<int, list<array{show:string,city:string,date:?string,date_announced:bool,is_past:bool,status:string,category?:string,package?:string}>>
     */
    private function capParticipationsAcrossChildren(array $byChild): array
    {
        $total = 0;
        foreach ($byChild as $list) {
            $total += count($list);
        }
        if ($total <= 24) {
            return $byChild;
        }

        foreach (array_reverse(array_keys($byChild), true) as $childId) {
            while ($total > 24 && $byChild[$childId] !== []) {
                $last = $byChild[$childId][count($byChild[$childId]) - 1];
                if (! ($last['is_past'] ?? false)) {
                    break;
                }
                array_pop($byChild[$childId]);
                $total--;
            }
        }

        foreach (array_reverse(array_keys($byChild), true) as $childId) {
            while ($total > 24 && $byChild[$childId] !== []) {
                array_pop($byChild[$childId]);
                $total--;
            }
        }

        return $byChild;
    }

    /**
     * @param  list<array{id:int,name:?string,language:?string}>  $records
     * @return list<array{id:int,name:?string,language:?string}>
     */
    private function uniqueIdentityRecords(array $records): array
    {
        $unique = [];
        foreach ($records as $record) {
            $unique[$record['id']] = $record;
        }

        return array_values($unique);
    }

    /**
     * @return array{id:int,name:?string,language:?string}
     */
    private function identityRecord(object $row): array
    {
        $name = isset($row->name) ? trim((string) $row->name) : '';
        $language = isset($row->language) ? trim((string) $row->language) : '';

        return [
            'id' => (int) $row->id,
            'name' => $name !== '' ? $name : null,
            'language' => $language !== '' ? $language : null,
        ];
    }

    private function clientIdentityQuery(): Builder
    {
        return DB::connection('jfs')
            ->table('app_users')
            ->where('role', 'client')
            ->where(function ($query): void {
                $query->whereNull('status')->orWhere('status', '!=', 'blocked');
            });
    }

    /**
     * @param  list<array{name:string,city:string,location:string,starts_at:?string,ends_at:?string,date_announced?:bool,is_past?:bool,description:?string}>  $events
     */
    public function eventsFactBlock(array $events): string
    {
        if ($events === []) {
            return "LIVE EVENT FACTS:\nNo events were returned from the main project database.";
        }

        $upcoming = [];
        $past = [];
        foreach ($events as $event) {
            $announced = (bool) ($event['date_announced'] ?? true);
            $date = $announced
                ? ($this->formatDate($event['starts_at'] ?? null) ?? 'not announced yet')
                : 'NOT ANNOUNCED YET — say the date is at the confirmation stage and will be announced soon; never name or guess it';

            $line = '- '.$event['name']
                .' | city: '.$event['city']
                .' | location: '.$event['location']
                .' | date: '.$date;
            if ($announced && filled($event['ends_at'] ?? null)) {
                $line .= ' | ends: '.$this->formatDate($event['ends_at']);
            }
            if (filled($event['description'])) {
                $line .= ' | '.$event['description'];
            }

            if ($event['is_past'] ?? false) {
                $past[] = $line;
            } else {
                $upcoming[] = $line;
            }
        }

        $lines = [
            'LIVE EVENT FACTS (authoritative, public). Today is '.CarbonImmutable::now()->toDateString().'.',
            'Only list a date when it is given below. If a date is NOT ANNOUNCED YET, say that the date is at the confirmation stage and will be announced soon. Do not say it is "not determined" or "unknown", never invent or estimate it, and do not use a date from another city.',
            'Never present a past show as upcoming and never invite anyone to a date that has already passed. Past shows may only be mentioned as shows that already happened.',
        ];
        if ($upcoming !== []) {
            $lines[] = 'UPCOMING SHOWS (these are the only ones you may offer):';
            array_push($lines, ...$upcoming);
        } else {
            $lines[] = 'UPCOMING SHOWS: none with a confirmed date yet — say the dates of the next shows are at the confirmation stage and will be announced soon.';
        }
        if ($past !== []) {
            $lines[] = 'PAST SHOWS (already happened — do not offer these dates):';
            array_push($lines, ...$past);
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<array{
     *     name:string,
     *     city:string,
     *     starts_at:?string,
     *     date_announced:bool,
     *     is_past:bool,
     *     brand_count:int,
     *     brands:list<string>
     * }>
     */
    public function publicBrandLineups(): array
    {
        $this->lastReadFailed = false;

        if (! $this->isConfigured()) {
            $this->lastReadFailed = true;

            return [];
        }

        try {
            $events = DB::connection('jfs')
                ->table('events')
                ->orderBy('starts_at')
                ->get(['id', 'name', 'city', 'starts_at', 'client_show_event_date']);

            $brandRows = DB::connection('jfs')
                ->table('event_brand as eb')
                ->join('brands as b', 'b.id', '=', 'eb.brand_id')
                ->where(function ($query): void {
                    $query->whereNull('b.is_active')->orWhere('b.is_active', 1);
                })
                ->orderBy('b.name')
                ->get(['eb.event_id', 'b.name']);
        } catch (Throwable $exception) {
            $this->lastReadFailed = true;
            Log::warning('JFS brand lineup read failed.', ['message' => $exception->getMessage()]);

            return [];
        }

        $byEvent = [];
        foreach ($brandRows as $row) {
            $eventId = (int) $row->event_id;
            $name = trim((string) $row->name);
            if ($name === '') {
                continue;
            }
            $byEvent[$eventId] ??= [];
            if (! in_array($name, $byEvent[$eventId], true)) {
                $byEvent[$eventId][] = $name;
            }
        }

        return $events->map(function ($event) use ($byEvent): array {
            $startsAt = $event->starts_at ? (string) $event->starts_at : null;
            $announced = $this->dateIsAnnounced($event->client_show_event_date ?? null);
            $brands = $byEvent[(int) $event->id] ?? [];

            return [
                'name' => (string) $event->name,
                'city' => (string) $event->city,
                'starts_at' => $announced ? $startsAt : null,
                'date_announced' => $announced,
                'is_past' => $this->isPast($startsAt),
                'brand_count' => count($brands),
                'brands' => $brands,
            ];
        })->all();
    }

    /**
     * @param  list<array{name:string,city:string,starts_at:?string,date_announced?:bool,is_past:bool,brand_count:int,brands:list<string>}>  $lineups
     */
    public function brandsFactBlock(array $lineups): string
    {
        if ($lineups === []) {
            return "LIVE BRAND FACTS:\nNo brand/event lineup was returned from the main project database.";
        }

        $past = [];
        $upcoming = [];
        foreach ($lineups as $lineup) {
            $announced = (bool) ($lineup['date_announced'] ?? true);
            $line = '- '.$lineup['city']
                .' | '.$lineup['name']
                .' | date: '.($announced
                    ? ($this->formatDate($lineup['starts_at'] ?? null) ?? 'not announced yet')
                    : 'NOT ANNOUNCED YET — at the confirmation stage, never name or guess it')
                .' | brands: '.$lineup['brand_count'];
            if ($lineup['brands'] !== []) {
                $line .= ' | '.implode(', ', $lineup['brands']);
            } else {
                $line .= ' | lineup not published yet';
            }
            if ($lineup['is_past']) {
                $past[] = $line;
            } else {
                $upcoming[] = $line;
            }
        }

        $lines = [
            'LIVE BRAND FACTS (authoritative, from the main JFS project). Today is '.CarbonImmutable::now()->toDateString().'. Use only these brand names. Do not invent brands or extra cities.',
            'A date marked NOT ANNOUNCED YET must never be named: say it is at the confirmation stage and will be announced soon.',
        ];
        if ($upcoming !== []) {
            $lines[] = 'UPCOMING SHOWS:';
            array_push($lines, ...$upcoming);
        }
        if ($past !== []) {
            $lines[] = 'PAST SHOWS (already happened — do not offer these dates):';
            array_push($lines, ...$past);
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array{status:string,client:?array,children:list<array>}  $lookup
     */
    public function clientFactBlock(array $lookup): string
    {
        $status = $lookup['status'] ?? 'not_found';
        if ($status === 'found') {
            return "CLIENT LOOKUP FACTS:\nstatus=found\nTell the customer only that they were found and the information was passed to the manager. Do not list children or internal fields.";
        }

        return "CLIENT LOOKUP FACTS:\nstatus={$status}\nTell the customer the request was accepted and a manager will follow up. Do not say an application is missing.";
    }

    private function localizedText(mixed $value): ?string
    {
        $data = $value;
        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            $data = is_array($decoded) ? $decoded : null;
        }

        if (! is_array($data) || $data === []) {
            return null;
        }

        foreach (['en', 'ru', 'uk', 'es'] as $locale) {
            $text = $this->plainText($data[$locale] ?? null);
            if ($text !== null) {
                return $text;
            }
        }

        foreach ($data as $item) {
            $text = $this->plainText($item);
            if ($text !== null) {
                return $text;
            }
        }

        return null;
    }

    private function plainText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $text = trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($text === '') {
            return null;
        }

        if (mb_strlen($text) > 800) {
            $text = rtrim(mb_substr($text, 0, 797)).'...';
        }

        return $text;
    }
}
