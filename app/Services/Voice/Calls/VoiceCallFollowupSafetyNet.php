<?php

namespace App\Services\Voice\Calls;

use App\Models\VoiceCall;
use App\Models\VoiceCallAnalysis;
use App\Models\VoiceFollowup;
use App\Services\Voice\Followup\VoiceFollowupNotifier;
use App\Services\Voice\Followup\VoiceFollowupRecorder;
use Illuminate\Support\Facades\Log;
use Throwable;

final class VoiceCallFollowupSafetyNet
{
    public function __construct(
        private readonly VoiceCallPostCallAnalyzer $analyzer,
        private readonly VoiceFollowupRecorder $recorder,
        private readonly VoiceFollowupNotifier $notifier,
    ) {}

    public function process(VoiceCall $call): VoiceCallAnalysis
    {
        $existing = $this->recorder->existingForConversation((string) $call->elevenlabs_conversation_id);
        $analysis = $this->analyzer->analyze($call);
        $liveCreated = $existing !== null;
        $analysis = new VoiceCallPostCallAnalysis(
            intent: $analysis->intent,
            department: $analysis->department,
            humanFollowupRequired: $analysis->humanFollowupRequired,
            callbackRequested: $analysis->callbackRequested,
            callbackCommittedByAgent: $analysis->callbackCommittedByAgent,
            liveFollowupCreated: $liveCreated,
            summary: $analysis->summary,
            unresolvedQuestions: $analysis->unresolvedQuestions,
            customerName: $analysis->customerName,
            childName: $analysis->childName,
            showCity: $analysis->showCity,
            callbackPhone: $analysis->callbackPhone,
            preferredCallbackTime: $analysis->preferredCallbackTime,
            reason: $analysis->reason,
        );

        if ($existing !== null) {
            $this->recorder->attachCallIfMissing($existing, $call);
            if (! $existing->telegramDelivered()) {
                $this->notifier->sendIfNeeded($existing, $existing->createdBy() === VoiceFollowup::CREATED_BY_SAFETY_NET);
            }
        } elseif ($analysis->needsSafetyNetFollowup()) {
            try {
                $this->recorder->recordFromSafetyNet($call, $this->safetyFields($call, $analysis));
            } catch (Throwable $exception) {
                Log::warning('voice.followup.safety_net_failed', [
                    'voice_call_id' => $call->id,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        return $this->store($call, $analysis);
    }

    /**
     * @return array{
     *     department: string,
     *     reason: string,
     *     callback_requested: bool,
     *     callback_phone: ?string,
     *     preferred_callback_time: ?string,
     *     customer_name: ?string,
     *     child_name: ?string,
     *     show_city: ?string,
     *     summary: ?string
     * }
     */
    private function safetyFields(VoiceCall $call, VoiceCallPostCallAnalysis $analysis): array
    {
        $reason = $analysis->reason
            ?: ($analysis->callbackCommittedByAgent
                ? 'Agent promised a manager callback during the call.'
                : 'Caller asked for a human follow-up.');

        return [
            'department' => $analysis->department ?? VoiceFollowup::DEPARTMENT_SALES,
            'reason' => $reason,
            'callback_requested' => $analysis->callbackRequested || $analysis->callbackCommittedByAgent,
            'callback_phone' => $analysis->callbackPhone,
            'preferred_callback_time' => $analysis->preferredCallbackTime,
            'customer_name' => $analysis->customerName,
            'child_name' => $analysis->childName,
            'show_city' => $analysis->showCity,
            'summary' => $analysis->summary ?: $call->summary,
        ];
    }

    private function store(VoiceCall $call, VoiceCallPostCallAnalysis $analysis): VoiceCallAnalysis
    {
        $attributes = [
            'intent' => $analysis->intent,
            'department' => $analysis->department,
            'human_followup_required' => $analysis->humanFollowupRequired,
            'callback_requested' => $analysis->callbackRequested,
            'callback_committed_by_agent' => $analysis->callbackCommittedByAgent,
            'live_followup_created' => $analysis->liveFollowupCreated,
            'summary' => $analysis->summary,
            'unresolved_questions' => $analysis->unresolvedQuestions,
            'metadata' => ['source' => 'post_call'],
        ];

        $row = VoiceCallAnalysis::query()->where('voice_call_id', $call->id)->first();
        if ($row !== null) {
            $row->forceFill($attributes)->save();

            return $row;
        }

        return VoiceCallAnalysis::query()->create(['voice_call_id' => $call->id] + $attributes);
    }
}
