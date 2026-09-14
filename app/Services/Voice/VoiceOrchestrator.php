<?php

namespace App\Services\Voice;

use App\Services\Voice\Exceptions\UnknownVoiceToolException;
use App\Services\Voice\Exceptions\VoiceToolExecutionException;
use App\Services\Voice\Tools\VoiceToolInterface;
use App\Services\Voice\Tools\VoiceToolRegistry;
use Illuminate\Support\Facades\Log;
use JsonException;
use Throwable;

final class VoiceOrchestrator
{
    public function __construct(
        private readonly VoiceToolRegistry $registry,
    ) {}

    /**
     * OpenAI-compatible function tool catalog for the voice-runtime LLM request.
     *
     * @return list<array{type: string, function: array{name: string, description: string, parameters: array<string, mixed>}}>
     */
    public function catalog(): array
    {
        return array_map(static function (VoiceToolInterface $tool): array {
            return [
                'type' => 'function',
                'function' => [
                    'name' => $tool->name(),
                    'description' => $tool->description(),
                    'parameters' => $tool->inputSchema(),
                ],
            ];
        }, $this->registry->all());
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function execute(string $name, array $arguments, string $requestId = ''): array
    {
        $started = hrtime(true);

        Log::info('voice.tool.requested', [
            'requestId' => $requestId,
            'tool' => $name,
            'arguments_type' => gettype($arguments),
            'arguments_count' => count($arguments),
        ]);

        if (! $this->registry->has($name)) {
            Log::info('voice.tool.failed', [
                'requestId' => $requestId,
                'tool' => $name,
                'status' => 'unknown_tool',
                'latency_ms' => $this->latencyMs($started),
            ]);

            throw new UnknownVoiceToolException($name);
        }

        try {
            $result = $this->registry->get($name)->execute($arguments);
            json_encode($result, JSON_THROW_ON_ERROR);

            Log::info('voice.tool.completed', [
                'requestId' => $requestId,
                'tool' => $name,
                'status' => 'ok',
                'latency_ms' => $this->latencyMs($started),
                'result_type' => gettype($result),
            ]);

            return $result;
        } catch (UnknownVoiceToolException $exception) {
            throw $exception;
        } catch (JsonException $exception) {
            Log::error('voice.tool.failed', [
                'requestId' => $requestId,
                'tool' => $name,
                'status' => 'invalid_result',
                'latency_ms' => $this->latencyMs($started),
                'exception' => $exception::class,
            ]);

            throw new VoiceToolExecutionException($name, $exception);
        } catch (Throwable $exception) {
            Log::error('voice.tool.failed', [
                'requestId' => $requestId,
                'tool' => $name,
                'status' => 'error',
                'latency_ms' => $this->latencyMs($started),
                'exception' => $exception::class,
            ]);

            throw new VoiceToolExecutionException($name, $exception);
        }
    }

    private function latencyMs(int $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
