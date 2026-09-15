<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Voice\Prompt\VoiceAssistantPromptBuilder;
use Illuminate\Http\JsonResponse;

class VoiceContextController extends Controller
{
    public function __invoke(VoiceAssistantPromptBuilder $builder): JsonResponse
    {
        return response()->json($builder->build()->toArray());
    }
}
