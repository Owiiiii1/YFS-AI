<?php

namespace App\Services\Jfs;

use App\Services\Identity\IdentityNameMatcher;

final class JfsIdentityMatch
{
    /**
     * Digit keys for a phone query. US 10-digit and 11-digit (leading 1) are
     * treated as equivalent. No broader fuzzy matching.
     *
     * @return list<string>
     */
    public static function phoneDigitKeys(string $phone): array
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (strlen($digits) < 8 || strlen($digits) > 15) {
            return [];
        }

        $keys = [$digits];
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $keys[] = substr($digits, 1);
        } elseif (strlen($digits) === 10) {
            $keys[] = '1'.$digits;
        }

        $keys = array_values(array_unique($keys));
        sort($keys);

        return $keys;
    }

    public static function phonesMatch(string $storedPhone, string $queryPhone): bool
    {
        $storedKeys = self::phoneDigitKeys($storedPhone);
        $queryKeys = self::phoneDigitKeys($queryPhone);
        if ($storedKeys === [] || $queryKeys === []) {
            return false;
        }

        return array_intersect($storedKeys, $queryKeys) !== [];
    }

    public static function nameMatches(string $storedName, string $query): bool
    {
        return IdentityNameMatcher::matchesExact($storedName, $query);
    }

    /**
     * Conservative multilingual / transliteration match. Never unique on its own.
     */
    public static function nameMatchesVariant(string $storedName, string $query): bool
    {
        return IdentityNameMatcher::matchesVariant($storedName, $query);
    }

    /**
     * @return list<string>
     */
    public static function words(string $value): array
    {
        return IdentityNameMatcher::words($value);
    }
}
