<?php

namespace App\Services\Voice\Prompt;

final class VoiceTopicResolver
{
    public function resolve(string $userText): string
    {
        $text = mb_strtolower(trim($userText));
        if ($text === '') {
            return 'general';
        }

        if (
            str_contains($text, 'latest yfs test status')
            || str_contains($text, 'latest test status')
            || str_contains($text, 'check the latest yfs test')
        ) {
            return 'test_status';
        }

        if (str_contains($text, 'yfs test event') || str_contains($text, 'test event')) {
            return 'test_event';
        }

        return 'general';
    }
}
