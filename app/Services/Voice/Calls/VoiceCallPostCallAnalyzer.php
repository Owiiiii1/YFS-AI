<?php

namespace App\Services\Voice\Calls;

use App\Models\VoiceCall;

interface VoiceCallPostCallAnalyzer
{
    public function analyze(VoiceCall $call): VoiceCallPostCallAnalysis;
}
