<?php

namespace App\Services\Voice\Phone;

final class PhoneNumberNormalizer
{
    /**
     * Stable unique key for matching callers.
     * Prefers E.164 (+ and 8–15 digits) when the input is a plausible phone number.
     * Never throws: unparseable values get a deterministic fallback so the same
     * input does not create infinite contact duplicates.
     */
    public function normalize(?string $raw): string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return '';
        }

        $working = $raw;
        if (str_starts_with($working, '00')) {
            $working = '+'.substr($working, 2);
        }

        $digits = preg_replace('/\D+/', '', $working) ?? '';
        if ($digits === '') {
            return 'x:'.hash('sha256', mb_strtolower($raw));
        }

        if (strlen($digits) >= 8 && strlen($digits) <= 15) {
            return '+'.$digits;
        }

        return 'd:'.$digits;
    }

    public function display(?string $raw): ?string
    {
        $raw = trim((string) $raw);

        return $raw === '' ? null : $raw;
    }
}
