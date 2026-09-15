<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ElevenLabs\ConversationInitiationClientData;
use App\Services\Voice\Prompt\VoiceAssistantPromptBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ElevenLabsConversationInitiationController extends Controller
{
    /**
     * Extra ElevenLabs telephony fields (caller_id, agent_id, called_number, call_sid, conversation_id)
     * are accepted and ignored until customer lookup exists.
     */
    public function __invoke(Request $request, VoiceAssistantPromptBuilder $builder): JsonResponse
    {
        return response()->json(
            ConversationInitiationClientData::fromRuntimePrompt($builder->build()),
        );
    }
}
