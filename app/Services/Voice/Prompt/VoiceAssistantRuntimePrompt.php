<?php

namespace App\Services\Voice\Prompt;

final class VoiceAssistantRuntimePrompt
{
    public function __construct(
        public readonly string $prompt,
        public readonly string $version,
        public readonly string $generatedAt,
    ) {}

    /**
     * @return array{prompt: string, version: string, generated_at: string}
     */
    public function toArray(): array
    {
        return [
            'prompt' => $this->prompt,
            'version' => $this->version,
            'generated_at' => $this->generatedAt,
        ];
    }
}
