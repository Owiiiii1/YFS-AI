<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ElevenLabs\ConversationInitiationClientData;
use App\Services\Voice\Contacts\VoiceContactDirectory;
use App\Services\Voice\Prompt\VoiceAssistantPromptBuilder;
use App\Support\VoiceSupportedLanguage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ElevenLabsConversationInitiationController extends Controller
{
    /**
     * Extra ElevenLabs telephony fields are accepted.
     * caller_id is used to find/create a VoiceContact and, when a supported
     * preferred_language is stored, to return agent.language.
     * calls_count is not incremented here (initiation retries are not completed calls).
     */
    public function __invoke(
        Request $request,
        VoiceAssistantPromptBuilder $builder,
        VoiceContactDirectory $directory,
    ): JsonResponse {
        $contact = $directory->findOrCreateFromCallerId(
            is_string($request->input('caller_id')) ? $request->input('caller_id') : null,
        );
        $language = VoiceSupportedLanguage::tryNormalize($contact?->preferred_language);

        return response()->json(
            ConversationInitiationClientData::fromRuntimePrompt($builder->build(), $language),
        );
    }
}
