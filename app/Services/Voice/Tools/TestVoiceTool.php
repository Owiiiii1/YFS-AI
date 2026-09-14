<?php

namespace App\Services\Voice\Tools;

/**
 * Temporary read-only test tool. Not production event, customer, or CRM data.
 */
final class TestVoiceTool implements VoiceToolInterface
{
    public const NAME = 'get_current_yfs_test_context';

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Returns test-only read-only Voice Consultant context from YFS. '
            .'Not production show, customer, or CRM data. '
            .'Use when the caller asks about the YFS test event.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'topic' => [
                    'type' => 'string',
                    'description' => 'Optional topic hint. Does not change the test-only result.',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function execute(array $arguments): array
    {
        $topic = isset($arguments['topic']) && is_string($arguments['topic'])
            ? trim($arguments['topic'])
            : '';

        $result = [
            'source' => 'yfs_ai_test',
            'status' => 'ok',
            'message' => 'Voice tool calling is working',
            'event_name' => 'YFS Test Event',
            'availability' => 'test-only',
            'note' => 'Synthetic test-only data. Not a production show, customer, or CRM record.',
        ];

        if ($topic !== '') {
            $result['topic'] = $topic;
        }

        return $result;
    }
}
