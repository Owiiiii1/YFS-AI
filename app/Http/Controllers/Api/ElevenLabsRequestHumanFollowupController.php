<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Voice\Identity\ElevenLabsTrustedCallContext;
use App\Services\Voice\Tools\RequestHumanFollowupVoiceTool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ElevenLabsRequestHumanFollowupController extends Controller
{
    public function __invoke(Request $request, RequestHumanFollowupVoiceTool $tool): JsonResponse
    {
        $trusted = ElevenLabsTrustedCallContext::fromRequest($request);

        return response()->json($tool->execute([
            'department' => $this->stringFrom($request, 'department'),
            'reason' => $this->stringFrom($request, 'reason'),
            'callback_requested' => $this->boolFrom($request, 'callback_requested'),
            'callback_phone' => $this->stringFrom($request, 'callback_phone'),
            'preferred_callback_time' => $this->stringFrom($request, 'preferred_callback_time'),
            'customer_name' => $this->stringFrom($request, 'customer_name'),
            'child_name' => $this->stringFrom($request, 'child_name'),
            'show_city' => $this->stringFrom($request, 'show_city'),
            'summary' => $this->stringFrom($request, 'summary'),
            'system__caller_id' => $trusted->callerId,
            'system__conversation_id' => $trusted->conversationId,
        ]));
    }

    private function stringFrom(Request $request, string $key): string
    {
        $value = $request->input($key, $request->input('arguments.'.$key));

        return is_string($value) ? trim($value) : '';
    }

    private function boolFrom(Request $request, string $key): bool
    {
        $value = $request->input($key, $request->input('arguments.'.$key));
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }
        if (! is_string($value)) {
            return false;
        }

        return in_array(strtolower(trim($value)), ['1', 'true', 'yes'], true);
    }
}
