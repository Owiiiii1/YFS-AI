<?php

namespace App\Services\Voice\Calls;

final class VoiceTranscriptNormalizer
{
    /**
     * Map an ElevenLabs post_call_transcription `data.transcript` array into
     * a safe list of dialogue turns. Tool-only / empty / unknown roles are dropped.
     *
     * @return list<array{role: string, speaker: string, message: string, time_in_call_secs: int|null}>
     */
    public function normalize(mixed $transcript): array
    {
        if (! is_array($transcript)) {
            return [];
        }

        $turns = [];
        foreach ($transcript as $item) {
            if (! is_array($item)) {
                continue;
            }

            $role = strtolower(trim((string) ($item['role'] ?? '')));
            $mapped = match ($role) {
                'user', 'client' => 'user',
                'agent', 'assistant' => 'agent',
                default => null,
            };
            if ($mapped === null) {
                continue;
            }

            $message = trim((string) ($item['message'] ?? ''));
            if ($message === '') {
                continue;
            }

            $time = $item['time_in_call_secs'] ?? null;

            $turns[] = [
                'role' => $mapped,
                'speaker' => $mapped === 'user' ? 'client' : 'assistant',
                'message' => $message,
                'time_in_call_secs' => is_numeric($time) ? (int) $time : null,
            ];
        }

        return $turns;
    }

    /**
     * @param  list<array{message?: string}>  $turns
     */
    public function preview(array $turns, int $max = 160): ?string
    {
        $text = trim(preg_replace(
            '/\s+/',
            ' ',
            implode(' ', array_map(
                static fn (array $turn): string => trim((string) ($turn['message'] ?? '')),
                $turns,
            )),
        ) ?? '');

        if ($text === '') {
            return null;
        }

        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return mb_substr($text, 0, $max - 1).'…';
    }
}
