<?php

namespace App\Services\Voice\Calls;

use App\Models\VoiceCall;
use App\Models\VoiceFollowup;
use App\Services\Ai\AiConnectionResolver;
use App\Services\Ai\AiReplyGenerator;
use Illuminate\Support\Facades\Log;
use Throwable;

final class AiVoiceCallPostCallAnalyzer implements VoiceCallPostCallAnalyzer
{
    public function __construct(
        private readonly AiReplyGenerator $ai,
    ) {}

    public function analyze(VoiceCall $call): VoiceCallPostCallAnalysis
    {
        $transcript = $this->transcriptText($call);
        if ($transcript === '') {
            return VoiceCallPostCallAnalysis::none();
        }

        try {
            $decoded = $this->ai->extractJsonForRole(
                AiConnectionResolver::ROLE_BOT_RUNTIME,
                $this->systemPrompt(),
                $transcript,
                $this->responseSchema(),
                [
                    'purpose' => 'voice_post_call_analysis',
                    'metadata' => ['voice_call_id' => $call->id],
                ],
            );
        } catch (Throwable $exception) {
            Log::warning('voice.call_analysis.ai_failed', [
                'voice_call_id' => $call->id,
                'message' => $exception->getMessage(),
            ]);

            return VoiceCallPostCallAnalysis::none();
        }

        return $this->fromDecoded($decoded);
    }

    /**
     * @param  array<string, mixed>  $decoded
     */
    private function fromDecoded(array $decoded): VoiceCallPostCallAnalysis
    {
        $department = strtolower(trim((string) ($decoded['department'] ?? '')));
        if (! in_array($department, [VoiceFollowup::DEPARTMENT_SALES, VoiceFollowup::DEPARTMENT_SUPPORT], true)) {
            $department = null;
        }

        $questions = $decoded['unresolved_questions'] ?? [];
        if (! is_array($questions)) {
            $questions = [];
        }
        $questions = array_values(array_filter(array_map(
            static fn ($item): ?string => is_string($item) && trim($item) !== '' ? trim($item) : null,
            $questions,
        )));

        return new VoiceCallPostCallAnalysis(
            intent: $this->nullableString($decoded['intent'] ?? null, 80),
            department: $department,
            humanFollowupRequired: $this->bool($decoded['human_followup_required'] ?? false),
            callbackRequested: $this->bool($decoded['callback_requested'] ?? false),
            callbackCommittedByAgent: $this->bool($decoded['callback_committed_by_agent'] ?? false),
            liveFollowupCreated: $this->bool($decoded['live_followup_created'] ?? false),
            summary: $this->nullableString($decoded['summary'] ?? null, 1000),
            unresolvedQuestions: array_slice($questions, 0, 8),
            customerName: $this->nullableString($decoded['customer_name'] ?? null, 120),
            childName: $this->nullableString($decoded['child_name'] ?? null, 120),
            showCity: $this->nullableString($decoded['show_city'] ?? null, 120),
            callbackPhone: $this->nullableString($decoded['callback_phone'] ?? null, 32),
            preferredCallbackTime: $this->nullableString($decoded['preferred_callback_time'] ?? null, 120),
            reason: $this->nullableString($decoded['reason'] ?? null, 500),
        );
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
You analyze a Young Fashion Show voice-call transcript after the call ended.
Return JSON only. Do not invent application status, identity, or phone numbers that were not spoken.
live_followup_created must be false; Laravel sets the real value.

human_followup_required is true only if the caller asked a human/manager/Sales/Support to contact them, or the agent promised that a request was passed to a team.
callback_requested is true only if the caller asked to be called back.
callback_committed_by_agent is true only if the agent said they passed, sent, or transferred the request, or that a manager would contact the caller.
department: sales for new applications, pricing, new participation, unknown leads; support for an existing customer's current participation after a contract. Null if neither applies.
Do not require YFS identity for a Sales lead.
PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    private function responseSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'intent' => ['type' => 'string'],
                'department' => ['type' => 'string'],
                'human_followup_required' => ['type' => 'boolean'],
                'callback_requested' => ['type' => 'boolean'],
                'callback_committed_by_agent' => ['type' => 'boolean'],
                'live_followup_created' => ['type' => 'boolean'],
                'summary' => ['type' => 'string'],
                'unresolved_questions' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'customer_name' => ['type' => 'string'],
                'child_name' => ['type' => 'string'],
                'show_city' => ['type' => 'string'],
                'callback_phone' => ['type' => 'string'],
                'preferred_callback_time' => ['type' => 'string'],
                'reason' => ['type' => 'string'],
            ],
            'required' => [
                'human_followup_required',
                'callback_requested',
                'callback_committed_by_agent',
            ],
        ];
    }

    private function transcriptText(VoiceCall $call): string
    {
        $turns = is_array($call->transcript) ? $call->transcript : [];
        $lines = [];
        foreach ($turns as $turn) {
            if (! is_array($turn)) {
                continue;
            }
            $role = strtolower((string) ($turn['role'] ?? $turn['speaker'] ?? ''));
            $message = trim((string) ($turn['message'] ?? ''));
            if ($message === '') {
                continue;
            }
            $label = $role === 'user' || $role === 'client' ? 'Caller' : 'Agent';
            $lines[] = $label.': '.$message;
        }

        $text = implode("\n", $lines);
        if (mb_strlen($text) > 12000) {
            return mb_substr($text, 0, 12000);
        }

        return $text;
    }

    private function nullableString(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '' || strtolower($value) === 'null') {
            return null;
        }

        return mb_strlen($value) <= $max ? $value : mb_substr($value, 0, $max);
    }

    private function bool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }
        if (! is_string($value)) {
            return false;
        }

        return in_array(strtolower(trim($value)), ['1', 'true', 'yes'], true);
    }
}
