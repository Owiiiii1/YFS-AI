<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Ai\AiConnectionResolver;
use App\Services\ElevenLabs\ElevenLabsSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

class VoiceRuntimeConfigController extends Controller
{
    public function __construct(
        private readonly AiConnectionResolver $aiConnectionResolver,
        private readonly ElevenLabsSettingsService $elevenLabsSettings,
    ) {}

    public function __invoke(): JsonResponse
    {
        return response()->json([
            'llm' => $this->llmConfig(),
            'elevenlabs' => [
                'api_key' => $this->elevenLabsApiKey(),
            ],
        ]);
    }

    private function elevenLabsApiKey(): string
    {
        try {
            return $this->elevenLabsSettings->apiKey() ?? '';
        } catch (Throwable $exception) {
            Log::warning('voice-runtime.config.elevenlabs_unavailable', [
                'exception' => $exception::class,
            ]);

            return '';
        }
    }

    /**
     * @return array{provider: string, model: string, api_key: string}
     */
    private function llmConfig(): array
    {
        try {
            $connection = $this->aiConnectionResolver->resolve(AiConnectionResolver::ROLE_BOT_RUNTIME);

            return [
                'provider' => (string) $connection['provider'],
                'model' => (string) $connection['model'],
                'api_key' => (string) $connection['api_key'],
            ];
        } catch (Throwable $exception) {
            Log::warning('voice-runtime.config.llm_unavailable', [
                'exception' => $exception::class,
            ]);

            return [
                'provider' => '',
                'model' => '',
                'api_key' => '',
            ];
        }
    }
}
