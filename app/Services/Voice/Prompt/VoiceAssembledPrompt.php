<?php

namespace App\Services\Voice\Prompt;

final class VoiceAssembledPrompt
{
    /**
     * @param  list<string>  $allowedTools
     * @param  list<string>  $sectionNames
     */
    public function __construct(
        public readonly string $text,
        public readonly string $activeTopic,
        public readonly string $responsePath,
        public readonly array $allowedTools,
        public readonly array $sectionNames,
        public readonly int $promptChars,
    ) {}
}
