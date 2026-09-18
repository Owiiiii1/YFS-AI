<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\AnalyzeVoiceCallJob;
use App\Services\Voice\Calls\ElevenLabsPostCallPersister;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ElevenLabsPostCallWebhookController extends Controller
{
    public function __invoke(Request $request, ElevenLabsPostCallPersister $persister): JsonResponse
    {
        $payload = $request->all();
        $type = is_string($payload['type'] ?? null) ? $payload['type'] : '';

        if ($type !== 'post_call_transcription') {
            return response()->json([
                'ok' => true,
                'ignored' => true,
            ]);
        }

        $call = $persister->persist($payload);
        if ($call !== null) {
            AnalyzeVoiceCallJob::dispatch($call->id);
        }

        return response()->json(['ok' => true]);
    }
}
