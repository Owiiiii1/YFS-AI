<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Voice\Identity\ElevenLabsTrustedCallContext;
use App\Services\Voice\Tools\ResolveCustomerIdentityVoiceTool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ElevenLabsResolveCustomerIdentityController extends Controller
{
    public function __invoke(Request $request, ResolveCustomerIdentityVoiceTool $tool): JsonResponse
    {
        $trusted = ElevenLabsTrustedCallContext::fromRequest($request);

        return response()->json($tool->execute([
            'name' => $this->stringFrom($request, 'name'),
            'child_name' => $this->stringFrom($request, 'child_name'),
            'on_behalf_of' => $trusted->onBehalfOf,
            'system__caller_id' => $trusted->callerId,
            'system__conversation_id' => $trusted->conversationId,
        ]));
    }

    private function stringFrom(Request $request, string $key): string
    {
        $value = $request->input($key, $request->input('arguments.'.$key));

        return is_string($value) ? trim($value) : '';
    }
}
