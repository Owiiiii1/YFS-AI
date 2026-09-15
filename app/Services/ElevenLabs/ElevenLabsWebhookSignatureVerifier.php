<?php

namespace App\Services\ElevenLabs;

final class ElevenLabsWebhookSignatureVerifier
{
    public const TOLERANCE_SECONDS = 1800;

    public function verify(string $rawBody, ?string $header, string $secret, ?int $now = null): bool
    {
        if ($secret === '' || $header === null || trim($header) === '') {
            return false;
        }

        $timestamp = null;
        $signature = null;

        foreach (explode(',', $header) as $part) {
            $part = trim($part);
            if (str_starts_with($part, 't=')) {
                $timestamp = substr($part, 2);
            } elseif (str_starts_with($part, 'v0=')) {
                $signature = substr($part, 3);
            }
        }

        if ($timestamp === null || $timestamp === '' || ! ctype_digit($timestamp) || $signature === null || $signature === '') {
            return false;
        }

        $now ??= time();
        if (abs($now - (int) $timestamp) > self::TOLERANCE_SECONDS) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);

        return hash_equals($expected, $signature);
    }
}
