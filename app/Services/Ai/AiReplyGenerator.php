<?php

namespace App\Services\Ai;

use App\Models\AiRun;
use App\Models\BotSetting;
use App\Models\BotDecisionTrace;
use App\Services\Bot\BotDecisionTraceService;
use Closure;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class AiReplyGenerator
{
    public function __construct(
        private readonly AiConnectionResolver $connections,
    ) {}

    /**
     * @param  array<string, mixed>  $trace
     */
    public function generate(
        string $systemPrompt,
        string $userMessage,
        ?string $conversationContext = null,
        array $trace = [],
    ): string {
        return $this->generateForRole(
            AiConnectionResolver::ROLE_BOT_RUNTIME,
            $systemPrompt,
            $userMessage,
            $conversationContext,
            $trace,
        );
    }

    /**
     * @param  array<string, mixed>  $trace
     */
    public function generateForRole(
        string $role,
        string $systemPrompt,
        string $userMessage,
        ?string $conversationContext = null,
        array $trace = [],
    ): string
    {
        $prompt = trim($userMessage);
        if ($conversationContext !== null && trim($conversationContext) !== '') {
            $prompt = "Conversation history:\n".trim($conversationContext)."\n\nLatest customer message:\n".$prompt;
        }

        $connection = $this->connections->resolve($role);

        return $this->track(
            $connection,
            $systemPrompt,
            $prompt,
            $trace + ['purpose' => 'reply_generate'],
            fn (): string => $this->dispatchText($connection, $systemPrompt, $prompt),
        )[0];
    }

    /**
     * @return array<string, mixed>
     * @param  array<string, mixed>  $trace
     */
    public function extractJson(string $systemPrompt, string $userMessage, array $trace = []): array
    {
        return $this->extractJsonForRole(
            AiConnectionResolver::ROLE_BOT_RUNTIME,
            $systemPrompt,
            $userMessage,
            null,
            $trace,
        );
    }

    /**
     * @param  array<string, mixed>|null  $responseSchema
     * @param  array<string, mixed>  $trace
     * @return array<string, mixed>
     */
    public function extractJsonForRole(
        string $role,
        string $systemPrompt,
        string $userMessage,
        ?array $responseSchema = null,
        array $trace = [],
    ): array {
        $connection = $this->connections->resolve($role);
        $jsonSystem = $systemPrompt."\n\nRespond with valid JSON only. No markdown fences, no commentary.";

        [$text, $run] = $this->track(
            $connection,
            $jsonSystem,
            $userMessage,
            $trace + ['purpose' => 'structured_extract'],
            fn (): string => $this->dispatchText(
                $connection,
                $jsonSystem,
                $userMessage,
                $responseSchema,
                $role === AiConnectionResolver::ROLE_PROMPT_ANALYSIS,
            ),
        );
        $decoded = $this->decodeJson($text);
        $run->forceFill(['parsed_result' => $decoded])->save();

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     * @param  array<string, mixed>  $trace
     */
    public function extractJsonWithImage(
        string $systemPrompt,
        string $userMessage,
        string $imagePath,
        string $mimeType,
        array $trace = [],
    ): array {
        $connection = $this->connections->resolve(AiConnectionResolver::ROLE_BOT_RUNTIME);
        $contents = file_get_contents($imagePath);
        if ($contents === false) {
            throw new RuntimeException('Unable to read image for AI analysis.');
        }

        $jsonSystem = $systemPrompt."\n\nRespond with valid JSON only. No markdown fences, no commentary.";
        $base64 = base64_encode($contents);

        [$text, $run] = $this->track(
            $connection,
            $jsonSystem,
            $userMessage,
            $trace + ['purpose' => 'receipt_verify', 'metadata' => ['mime_type' => $mimeType]],
            fn (): string => $this->dispatchImage(
                $connection,
                $jsonSystem,
                $userMessage,
                $base64,
                $mimeType,
            ),
        );
        $decoded = $this->decodeJson($text);
        $run->forceFill(['parsed_result' => $decoded])->save();

        return $decoded;
    }

    /**
     * @param  array{role:string,provider:string,model:string,api_key:string}  $connection
     * @param  array<string, mixed>|null  $responseSchema
     */
    private function dispatchText(
        array $connection,
        string $systemPrompt,
        string $userPrompt,
        ?array $responseSchema = null,
        bool $thinking = false,
    ): string {
        return match ($connection['provider']) {
            'openai' => $this->generateOpenAi($connection['api_key'], $connection['model'], $systemPrompt, $userPrompt),
            'anthropic' => $this->generateAnthropic($connection['api_key'], $connection['model'], $systemPrompt, $userPrompt),
            'gemini' => $this->generateGemini(
                $connection['api_key'],
                $connection['model'],
                $systemPrompt,
                $userPrompt,
                $responseSchema,
                $thinking,
            ),
            default => throw new RuntimeException('Unsupported AI provider: '.$connection['provider']),
        };
    }

    /**
     * @param  array{role:string,provider:string,model:string,api_key:string}  $connection
     */
    private function dispatchImage(
        array $connection,
        string $systemPrompt,
        string $userPrompt,
        string $base64,
        string $mimeType,
    ): string {
        return match ($connection['provider']) {
            'openai' => $this->generateOpenAiWithImage(
                $connection['api_key'], $connection['model'], $systemPrompt, $userPrompt, $base64, $mimeType,
            ),
            'anthropic' => $this->generateAnthropicWithImage(
                $connection['api_key'], $connection['model'], $systemPrompt, $userPrompt, $base64, $mimeType,
            ),
            'gemini' => $this->generateGeminiWithImage(
                $connection['api_key'], $connection['model'], $systemPrompt, $userPrompt, $base64, $mimeType,
            ),
            default => throw new RuntimeException('Unsupported AI provider: '.$connection['provider']),
        };
    }

    /**
     * @param  array{role:string,provider:string,model:string,api_key:string}  $connection
     * @param  array<string, mixed>  $trace
     * @return array{0:string,1:AiRun}
     */
    private function track(
        array $connection,
        string $systemPrompt,
        string $userPrompt,
        array $trace,
        Closure $callback,
    ): array {
        $started = hrtime(true);
        $decisionTraceId = $trace['decision_trace_id'] ?? BotDecisionTraceService::currentId();
        $conversationId = $trace['conversation_id'] ?? null;
        if ($conversationId === null && $decisionTraceId !== null) {
            $conversationId = BotDecisionTrace::query()->whereKey($decisionTraceId)->value('conversation_id');
        }
        BotDecisionTraceService::step('ai:'.(string) ($trace['purpose'] ?? 'unknown'));

        $run = AiRun::query()->create([
            'decision_trace_id' => $decisionTraceId,
            'conversation_id' => $conversationId,
            'purpose' => (string) ($trace['purpose'] ?? 'unknown'),
            'connection_role' => $connection['role'],
            'provider' => $connection['provider'],
            'model' => $connection['model'],
            'status' => 'running',
            'system_prompt_hash' => hash('sha256', $systemPrompt),
            'user_prompt_hash' => hash('sha256', $userPrompt),
            'system_prompt' => mb_substr($systemPrompt, 0, 200000),
            'user_prompt' => mb_substr($userPrompt, 0, 200000),
            'bot_prompt_revision' => $trace['bot_prompt_revision']
                ?? BotSetting::query()->value('prompt_revision'),
            'metadata' => $trace['metadata'] ?? null,
        ]);

        try {
            $text = $callback();
            $run->forceFill([
                'status' => 'success',
                'latency_ms' => (int) round((hrtime(true) - $started) / 1_000_000),
                'response_text' => mb_substr($text, 0, 200000),
            ])->save();

            return [$text, $run];
        } catch (Throwable $exception) {
            $run->forceFill([
                'status' => 'error',
                'latency_ms' => (int) round((hrtime(true) - $started) / 1_000_000),
                'error_message' => mb_substr($exception->getMessage(), 0, 10000),
            ])->save();

            throw $exception;
        }
    }

    private function generateOpenAi(string $apiKey, string $model, string $systemPrompt, string $userPrompt): string
    {
        $response = Http::timeout(60)
            ->withToken($apiKey)
            ->acceptJson()
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                'temperature' => 0.4,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                (string) ($response->json('error.message') ?: 'OpenAI chat failed with status '.$response->status())
            );
        }

        $text = trim((string) data_get($response->json(), 'choices.0.message.content', ''));

        if ($text === '') {
            throw new RuntimeException('OpenAI returned an empty reply.');
        }

        return $text;
    }

    private function generateOpenAiWithImage(
        string $apiKey,
        string $model,
        string $systemPrompt,
        string $userPrompt,
        string $base64,
        string $mimeType,
    ): string {
        $response = Http::timeout(90)
            ->withToken($apiKey)
            ->acceptJson()
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    [
                        'role' => 'user',
                        'content' => [
                            ['type' => 'text', 'text' => $userPrompt],
                            [
                                'type' => 'image_url',
                                'image_url' => ['url' => "data:{$mimeType};base64,{$base64}"],
                            ],
                        ],
                    ],
                ],
                'temperature' => 0.1,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                (string) ($response->json('error.message') ?: 'OpenAI vision failed with status '.$response->status())
            );
        }

        return $this->requireText(
            (string) data_get($response->json(), 'choices.0.message.content', ''),
            'OpenAI returned an empty vision reply.',
        );
    }

    private function generateAnthropic(string $apiKey, string $model, string $systemPrompt, string $userPrompt): string
    {
        $response = Http::timeout(60)
            ->withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
            ])
            ->acceptJson()
            ->post('https://api.anthropic.com/v1/messages', [
                'model' => $model,
                'max_tokens' => 1024,
                'system' => $systemPrompt,
                'messages' => [
                    ['role' => 'user', 'content' => $userPrompt],
                ],
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                (string) ($response->json('error.message') ?: 'Anthropic chat failed with status '.$response->status())
            );
        }

        $text = collect($response->json('content', []))
            ->filter(fn ($block): bool => is_array($block) && ($block['type'] ?? '') === 'text')
            ->map(fn (array $block): string => (string) ($block['text'] ?? ''))
            ->implode("\n");

        $text = trim($text);
        if ($text === '') {
            throw new RuntimeException('Anthropic returned an empty reply.');
        }

        return $text;
    }

    private function generateAnthropicWithImage(
        string $apiKey,
        string $model,
        string $systemPrompt,
        string $userPrompt,
        string $base64,
        string $mimeType,
    ): string {
        $response = Http::timeout(90)
            ->withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
            ])
            ->acceptJson()
            ->post('https://api.anthropic.com/v1/messages', [
                'model' => $model,
                'max_tokens' => 1024,
                'system' => $systemPrompt,
                'messages' => [[
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'image',
                            'source' => [
                                'type' => 'base64',
                                'media_type' => $mimeType,
                                'data' => $base64,
                            ],
                        ],
                        ['type' => 'text', 'text' => $userPrompt],
                    ],
                ]],
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                (string) ($response->json('error.message') ?: 'Anthropic vision failed with status '.$response->status())
            );
        }

        $text = collect($response->json('content', []))
            ->filter(fn ($block): bool => is_array($block) && ($block['type'] ?? '') === 'text')
            ->map(fn (array $block): string => (string) ($block['text'] ?? ''))
            ->implode("\n");

        return $this->requireText($text, 'Anthropic returned an empty vision reply.');
    }

    /**
     * @param  array<string, mixed>|null  $responseSchema
     */
    private function generateGemini(
        string $apiKey,
        string $model,
        string $systemPrompt,
        string $userPrompt,
        ?array $responseSchema = null,
        bool $thinking = false,
    ): string {
        $modelPath = str_starts_with($model, 'models/') ? $model : 'models/'.$model;
        $generationConfig = ['temperature' => $responseSchema === null ? 0.4 : 0.2];
        if ($responseSchema !== null) {
            $generationConfig['responseMimeType'] = 'application/json';
            $generationConfig['responseSchema'] = $responseSchema;
        }
        if ($thinking) {
            $generationConfig['thinkingConfig'] = ['thinkingLevel' => 'high'];
        }

        $response = Http::timeout(60)
            ->acceptJson()
            ->post('https://generativelanguage.googleapis.com/v1beta/'.$modelPath.':generateContent?key='.urlencode($apiKey), [
                'systemInstruction' => [
                    'parts' => [['text' => $systemPrompt]],
                ],
                'contents' => [
                    [
                        'role' => 'user',
                        'parts' => [['text' => $userPrompt]],
                    ],
                ],
                'generationConfig' => $generationConfig,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                (string) ($response->json('error.message') ?: 'Gemini chat failed with status '.$response->status())
            );
        }

        $text = collect($response->json('candidates.0.content.parts', []))
            ->map(fn ($part): string => is_array($part) ? (string) ($part['text'] ?? '') : '')
            ->implode("\n");

        $text = trim($text);
        if ($text === '') {
            throw new RuntimeException('Gemini returned an empty reply.');
        }

        return $text;
    }

    private function generateGeminiWithImage(
        string $apiKey,
        string $model,
        string $systemPrompt,
        string $userPrompt,
        string $base64,
        string $mimeType,
    ): string {
        $modelPath = str_starts_with($model, 'models/') ? $model : 'models/'.$model;

        $response = Http::timeout(90)
            ->acceptJson()
            ->post('https://generativelanguage.googleapis.com/v1beta/'.$modelPath.':generateContent?key='.urlencode($apiKey), [
                'systemInstruction' => [
                    'parts' => [['text' => $systemPrompt]],
                ],
                'contents' => [[
                    'role' => 'user',
                    'parts' => [
                        ['text' => $userPrompt],
                        ['inlineData' => ['mimeType' => $mimeType, 'data' => $base64]],
                    ],
                ]],
                'generationConfig' => ['temperature' => 0.1],
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                (string) ($response->json('error.message') ?: 'Gemini vision failed with status '.$response->status())
            );
        }

        $text = collect($response->json('candidates.0.content.parts', []))
            ->map(fn ($part): string => is_array($part) ? (string) ($part['text'] ?? '') : '')
            ->implode("\n");

        return $this->requireText($text, 'Gemini returned an empty vision reply.');
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $text): array
    {
        $text = trim($text);
        $text = preg_replace('/^```json\s*/i', '', $text) ?? $text;
        $text = preg_replace('/\s*```$/', '', $text) ?? $text;

        $decoded = json_decode($text, true);
        if (! is_array($decoded)) {
            throw new RuntimeException('AI returned invalid JSON for structured extraction.');
        }

        return $decoded;
    }

    private function requireText(string $text, string $message): string
    {
        $text = trim($text);
        if ($text === '') {
            throw new RuntimeException($message);
        }

        return $text;
    }
}
