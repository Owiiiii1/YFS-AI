<?php

namespace App\Services\Voice\Tools;

final class YfsCoreLiveToolSupport
{
    public const SOURCE = 'yfs_core';

    public const ERROR_SOURCE_UNAVAILABLE = 'source_unavailable';

    public const MESSAGE_NO_MATCHING_SHOWS = 'no_matching_shows';

    /**
     * @param  array<string, mixed>  $arguments
     */
    public static function stringArgument(array $arguments, string $key): string
    {
        $value = $arguments[$key] ?? null;

        return is_string($value) ? trim($value) : '';
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function filterByShowAndCity(array $rows, string $showName, string $city): array
    {
        return array_values(array_filter(
            $rows,
            static function (array $row) use ($showName, $city): bool {
                $name = (string) ($row['name'] ?? '');
                $rowCity = (string) ($row['city'] ?? '');

                return self::containsNormalized($name, $showName)
                    && self::containsNormalized($rowCity, $city);
            },
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @return array{
     *     ok: bool,
     *     tool: string,
     *     source: string,
     *     results: list<array<string, mixed>>,
     *     count: int,
     *     message?: string,
     *     error?: string
     * }
     */
    public static function payload(
        string $tool,
        bool $ok,
        array $results,
        ?string $message = null,
        ?string $error = null,
    ): array {
        $payload = [
            'ok' => $ok,
            'tool' => $tool,
            'source' => self::SOURCE,
            'results' => $results,
            'count' => count($results),
        ];

        if ($message !== null) {
            $payload['message'] = $message;
        }

        if ($error !== null) {
            $payload['error'] = $error;
        }

        return $payload;
    }

    /**
     * @return array{
     *     ok: false,
     *     tool: string,
     *     source: string,
     *     results: list<never>,
     *     count: 0,
     *     error: string
     * }
     */
    public static function unavailable(string $tool): array
    {
        return self::payload(
            $tool,
            false,
            [],
            error: self::ERROR_SOURCE_UNAVAILABLE,
        );
    }

    private static function containsNormalized(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }

        return str_contains(mb_strtolower($haystack), mb_strtolower($needle));
    }
}
