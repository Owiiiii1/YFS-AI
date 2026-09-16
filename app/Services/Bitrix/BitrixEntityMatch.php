<?php

namespace App\Services\Bitrix;

final class BitrixEntityMatch
{
    public const UNIQUE = 'unique';

    public const AMBIGUOUS = 'ambiguous';

    public const NOT_FOUND = 'not_found';

    public const UNAVAILABLE = 'unavailable';

    /**
     * @param  list<int>  $contactIds
     */
    public function __construct(
        public readonly string $status,
        public readonly array $contactIds = [],
        public readonly int $matchCount = 0,
        public readonly int $elapsedMs = 0,
    ) {}

    public static function unique(array $contactIds, int $elapsedMs = 0): self
    {
        $ids = array_values(array_unique(array_map('intval', $contactIds)));

        return new self(self::UNIQUE, $ids, 1, $elapsedMs);
    }

    public static function ambiguous(array $contactIds, int $elapsedMs = 0, ?int $matchCount = null): self
    {
        $ids = array_values(array_unique(array_map('intval', $contactIds)));
        $count = $matchCount ?? count($ids);

        return new self(self::AMBIGUOUS, $ids, max($count, count($ids)), $elapsedMs);
    }

    public static function notFound(int $elapsedMs = 0): self
    {
        return new self(self::NOT_FOUND, [], 0, $elapsedMs);
    }

    public static function unavailable(int $elapsedMs = 0): self
    {
        return new self(self::UNAVAILABLE, [], 0, $elapsedMs);
    }

    public function isUnique(): bool
    {
        return $this->status === self::UNIQUE && count($this->contactIds) === 1;
    }
}
