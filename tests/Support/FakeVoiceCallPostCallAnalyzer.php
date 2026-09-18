<?php

namespace Tests\Support;

use App\Models\VoiceCall;
use App\Services\Voice\Calls\VoiceCallPostCallAnalysis;
use App\Services\Voice\Calls\VoiceCallPostCallAnalyzer;

final class FakeVoiceCallPostCallAnalyzer implements VoiceCallPostCallAnalyzer
{
    public function __construct(
        public VoiceCallPostCallAnalysis $result = new VoiceCallPostCallAnalysis(
            intent: null,
            department: null,
            humanFollowupRequired: false,
            callbackRequested: false,
            callbackCommittedByAgent: false,
            liveFollowupCreated: false,
            summary: null,
            unresolvedQuestions: [],
        ),
    ) {}

    public function analyze(VoiceCall $call): VoiceCallPostCallAnalysis
    {
        return $this->result;
    }
}
