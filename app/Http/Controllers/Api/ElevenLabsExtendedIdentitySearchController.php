<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Voice\Identity\ElevenLabsTrustedCallContext;
use App\Services\Voice\Tools\GetExtendedIdentitySearchStatusVoiceTool;
use App\Services\Voice\Tools\StartExtendedIdentitySearchVoiceTool;
use App\Services\Voice\Tools\YfsCoreLiveToolSupport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ElevenLabsExtendedIdentitySearchController extends Controller
{
    public function start(Request $request, StartExtendedIdentitySearchVoiceTool $tool): JsonResponse
    {
        return response()->json($tool->execute($this->arguments($request)));
    }

    public function status(Request $request, GetExtendedIdentitySearchStatusVoiceTool $tool): JsonResponse
    {
        return response()->json($tool->execute($this->arguments($request)));
    }

    /**
     * @return array<string, mixed>
     */
    private function arguments(Request $request): array
    {
        $trusted = ElevenLabsTrustedCallContext::fromRequest($request);

        return [
            'name' => $this->stringFrom($request, 'name'),
            'child_name' => $this->stringFrom($request, 'child_name'),
            'show_city' => $this->stringFrom($request, 'show_city'),
            'package' => $this->stringFrom($request, 'package'),
            'email' => $this->stringFrom($request, 'email'),
            'system__caller_id' => $trusted->callerId,
            'system__conversation_id' => $trusted->conversationId,
        ];
    }

    private function stringFrom(Request $request, string $key): string
    {
        $value = $request->input($key, $request->input('arguments.'.$key));

        return is_string($value) ? trim($value) : '';
    }
}
