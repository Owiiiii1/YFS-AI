<?php

namespace App\Services\Voice\Tools;

use App\Services\Voice\Followup\VoiceFollowupRecorder;
use Illuminate\Support\Facades\Log;

final class RequestHumanFollowupVoiceTool implements VoiceToolInterface, VoiceToolMetadataProvider
{
    public const NAME = 'request_human_followup';

    public function __construct(
        private readonly VoiceFollowupRecorder $recorder,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Create a human follow-up for Sales or Support when the caller explicitly asks for a callback, a manager, or a person to contact them. '
            .'Call this before saying the request was passed to a team. Do not confirm transfer until ok is true. '
            .'Unknown Sales leads do not need YFS identity. '
            .'Pass department (sales|support), reason, callback_requested, and callback_phone only when the caller dictated a number. '
            .'Do not pass customer_id, app_user_id, or other internal ids. '
            .'Do not invent an application status or a callback number.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'department' => [
                    'type' => 'string',
                    'description' => 'sales for new applications, pricing, new participation, or potential clients. support for an existing customer’s current participation or organizational questions after a contract.',
                    'enum' => ['sales', 'support'],
                ],
                'reason' => [
                    'type' => 'string',
                    'description' => 'Short reason the caller wants a human, in the conversation language.',
                ],
                'callback_requested' => [
                    'type' => 'boolean',
                    'description' => 'True when the caller asked to be called back.',
                ],
                'callback_phone' => [
                    'type' => 'string',
                    'description' => 'Callback number only if the caller explicitly dictated it. Do not invent a number. Omit to use the trusted calling number when a callback was requested.',
                ],
                'preferred_callback_time' => [
                    'type' => 'string',
                    'description' => 'Caller preference only, not a booked appointment.',
                ],
                'customer_name' => [
                    'type' => 'string',
                    'description' => 'Parent name if spoken. Do not invent.',
                ],
                'child_name' => [
                    'type' => 'string',
                    'description' => 'Child name if spoken. Do not invent.',
                ],
                'show_city' => [
                    'type' => 'string',
                    'description' => 'Show city or event the caller named, if any.',
                ],
                'summary' => [
                    'type' => 'string',
                    'description' => 'One or two sentences of useful context. No full transcript. Do not invent application status.',
                ],
            ],
            'required' => ['department', 'reason'],
            'additionalProperties' => false,
        ];
    }

    public function execute(array $arguments): array
    {
        $result = $this->recorder->recordFromLiveTool($arguments);
        Log::info('voice.tools.request_human_followup', [
            'status' => $result['status'],
            'ok' => $result['ok'],
            'department' => $result['department'] ?? null,
        ]);

        return $result;
    }

    public function metadata(): VoiceToolMetadata
    {
        return new VoiceToolMetadata(
            category: 'action',
            estimatedLatency: 'short',
            fillerEnabled: true,
            readOnly: false,
            source: 'voice_followup',
        );
    }
}
