<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Voice\Identity\VoiceConversationInitiationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ElevenLabsConversationInitiationController extends Controller
{
    /**
     * Extra ElevenLabs telephony fields are accepted.
     * caller_id is used to find/create a VoiceContact and, when a supported
     * preferred_language is stored, to return agent.language.
     * conversation_id is stored on the contact for later tool binding.
     * Unique JFS phone matches add compact identity to VoiceContact metadata
     * and a CALLER CONTEXT prompt block. Ambiguous/unknown/unavailable must
     * not break this webhook. calls_count is not incremented here.
     */
    public function __invoke(
        Request $request,
        VoiceConversationInitiationService $initiation,
    ): JsonResponse {
        return response()->json(
            $initiation->payload(
                is_string($request->input('caller_id')) ? $request->input('caller_id') : null,
                is_string($request->input('conversation_id')) ? $request->input('conversation_id') : null,
            ),
        );
    }
}
