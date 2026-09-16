<?php

namespace App\Services\Voice\Identity;

final class CustomerIdentityResult
{
    public const UNIQUE = 'unique';

    public const AMBIGUOUS = 'ambiguous';

    public const NOT_FOUND = 'not_found';

    public const SOURCE_UNAVAILABLE = 'source_unavailable';

    public function __construct(
        public readonly string $status,
        public readonly string $matchMethod,
        public readonly int $matchCount = 0,
        public readonly ?int $yfsAppUserId = null,
        public readonly ?string $displayName = null,
        public readonly ?string $preferredLanguage = null,
    ) {}

    public static function unique(
        string $matchMethod,
        int $yfsAppUserId,
        ?string $displayName,
        ?string $preferredLanguage,
    ): self {
        return new self(
            self::UNIQUE,
            $matchMethod,
            1,
            $yfsAppUserId,
            $displayName,
            $preferredLanguage,
        );
    }

    public static function ambiguous(string $matchMethod, int $matchCount): self
    {
        return new self(self::AMBIGUOUS, $matchMethod, $matchCount);
    }

    public static function notFound(string $matchMethod): self
    {
        return new self(self::NOT_FOUND, $matchMethod);
    }

    public static function unavailable(string $matchMethod): self
    {
        return new self(self::SOURCE_UNAVAILABLE, $matchMethod);
    }

    public function isUnique(): bool
    {
        return $this->status === self::UNIQUE;
    }
}
