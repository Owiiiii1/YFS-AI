<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Voice\Exceptions\UnknownVoiceToolException;
use App\Services\Voice\Exceptions\VoiceToolExecutionException;
use App\Services\Voice\VoiceOrchestrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

class VoiceToolController extends Controller
{
    public function catalog(VoiceOrchestrator $orchestrator): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'tools' => $orchestrator->catalog(),
        ]);
    }

    public function execute(Request $request, VoiceOrchestrator $orchestrator): JsonResponse
    {
        $validated = $request->validate([
            'tool' => ['required', 'string', 'max:64'],
            'arguments' => ['sometimes', 'array'],
        ]);

        $tool = $validated['tool'];
        /** @var array<string, mixed> $arguments */
        $arguments = $validated['arguments'] ?? [];
        $requestId = $this->requestId($request);

        try {
            $result = $orchestrator->execute($tool, $arguments, $requestId);

            return response()->json([
                'ok' => true,
                'tool' => $tool,
                'result' => $result,
            ]);
        } catch (UnknownVoiceToolException) {
            return response()->json([
                'ok' => false,
                'tool' => $tool,
                'error' => [
                    'type' => 'unknown_tool',
                    'message' => 'Unknown voice tool',
                ],
            ], 404);
        } catch (VoiceToolExecutionException) {
            return response()->json([
                'ok' => false,
                'tool' => $tool,
                'error' => [
                    'type' => 'tool_failed',
                    'message' => 'Voice tool execution failed',
                ],
            ], 500);
        } catch (Throwable) {
            return response()->json([
                'ok' => false,
                'tool' => $tool,
                'error' => [
                    'type' => 'tool_failed',
                    'message' => 'Voice tool execution failed',
                ],
            ], 500);
        }
    }

    private function requestId(Request $request): string
    {
        $header = trim((string) $request->header('X-Request-Id', ''));
        if ($header !== '' && strlen($header) <= 128) {
            return $header;
        }

        return (string) Str::uuid();
    }
}
