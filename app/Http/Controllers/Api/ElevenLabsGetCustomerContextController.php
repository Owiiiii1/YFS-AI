<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Voice\Identity\ElevenLabsTrustedCallContext;
use App\Services\Voice\Tools\GetCustomerContextVoiceTool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ElevenLabsGetCustomerContextController extends Controller
{
    public function __invoke(Request $request, GetCustomerContextVoiceTool $tool): JsonResponse
    {
        $trusted = ElevenLabsTrustedCallContext::fromRequest($request);

        return response()->json($tool->execute([
            'system__caller_id' => $trusted->callerId,
            'system__conversation_id' => $trusted->conversationId,
        ]));
    }
}
