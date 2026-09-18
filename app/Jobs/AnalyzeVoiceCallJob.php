<?php

namespace App\Jobs;

use App\Models\VoiceCall;
use App\Services\Voice\Calls\VoiceCallFollowupSafetyNet;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class AnalyzeVoiceCallJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 60;

    /** @var list<int> */
    public array $backoff = [10, 30];

    public function __construct(
        public int $voiceCallId,
    ) {}

    public function uniqueId(): string
    {
        return 'voice-call-analysis-'.$this->voiceCallId;
    }

    public function handle(VoiceCallFollowupSafetyNet $safetyNet): void
    {
        $call = VoiceCall::query()->with('contact')->find($this->voiceCallId);
        if ($call === null) {
            return;
        }

        try {
            $safetyNet->process($call);
        } catch (Throwable $exception) {
            Log::warning('voice.call_analysis.job_failed', [
                'voice_call_id' => $this->voiceCallId,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
