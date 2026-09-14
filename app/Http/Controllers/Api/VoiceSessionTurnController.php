<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Voice\VoiceSessionTurnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class VoiceSessionTurnController extends Controller
{
    public function __invoke(Request $request, VoiceSessionTurnService $turns): JsonResponse
    {
        $validated = $request->validate([
            'session_id' => ['sometimes', 'nullable', 'string', 'max:128'],
            'user_text' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'caller_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'language' => ['sometimes', 'nullable', 'string', 'max:8'],
        ]);

        $requestId = trim((string) $request->header('X-Request-Id', ''));
        if ($requestId === '' || strlen($requestId) > 128) {
            $requestId = (string) Str::uuid();
        }

        $sessionId = trim((string) ($validated['session_id'] ?? ''));
        if ($sessionId === '') {
            $sessionId = $requestId;
        }

        return response()->json($turns->assemble(
            sessionId: $sessionId,
            userText: (string) ($validated['user_text'] ?? ''),
            requestId: $requestId,
            callerPhone: isset($validated['caller_phone']) ? (string) $validated['caller_phone'] : null,
            languageHint: isset($validated['language']) ? (string) $validated['language'] : null,
        ));
    }
}
