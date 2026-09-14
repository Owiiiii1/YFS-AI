<?php

namespace App\Services\Voice;

use App\Services\Voice\Context\VoiceSessionContextFactory;
use App\Services\Voice\Filler\VoiceFillerPhraseService;
use App\Services\Voice\Prompt\VoicePromptOrchestrator;
use App\Services\Voice\Tools\TestVoiceTool;
use App\Services\Voice\Tools\VoiceToolMetadataResolver;
use App\Services\Voice\Tools\VoiceToolRegistry;
use Illuminate\Support\Facades\Log;

final class VoiceSessionTurnService
{
    public function __construct(
        private readonly VoiceSessionContextFactory $contextFactory,
        private readonly VoicePromptOrchestrator $promptOrchestrator,
        private readonly VoiceFillerPhraseService $fillerPhrases,
        private readonly VoiceToolRegistry $tools,
        private readonly VoiceToolMetadataResolver $metadataResolver,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function assemble(
        string $sessionId,
        string $userText,
        string $requestId = '',
        ?string $callerPhone = null,
        ?string $languageHint = null,
    ): array {
        $context = $this->contextFactory->make($sessionId, $userText, $callerPhone, $languageHint);
        $prompt = $this->promptOrchestrator->assemble($context, $userText);

        $fillers = [];
        if ($this->fillerPhrases->enabled()) {
            foreach ($prompt->allowedTools as $toolName) {
                if (! $this->tools->has($toolName)) {
                    continue;
                }
                $metadata = $this->metadataResolver->resolve($this->tools->get($toolName));
                if (! $metadata->fillerEnabled) {
                    continue;
                }
                $phrase = $this->fillerPhrases->select(
                    $context->detectedLanguage,
                    $metadata->category,
                    $sessionId.'|'.$requestId.'|'.$toolName,
                );
                $fillers[$toolName] = [
                    'enabled' => true,
                    'language' => $phrase->language,
                    'category' => $phrase->category,
                    'phrase_id' => $phrase->id,
                    'text' => $phrase->text,
                ];
                Log::info('voice.filler.selected', [
                    'language' => $phrase->language,
                    'category' => $phrase->category,
                    'phrase_id' => $phrase->id,
                ]);
            }
        }

        $metadata = [];
        if ($this->tools->has(TestVoiceTool::NAME)) {
            $metadata[TestVoiceTool::NAME] = $this->metadataResolver
                ->resolve($this->tools->get(TestVoiceTool::NAME))
                ->toArray();
        }

        return [
            'ok' => true,
            'session_id' => $context->sessionId,
            'active_topic' => $prompt->activeTopic,
            'response_path' => $prompt->responsePath,
            'system_prompt' => $prompt->text,
            'allowed_tools' => $prompt->allowedTools,
            'section_names' => $prompt->sectionNames,
            'prompt_chars' => $prompt->promptChars,
            'detected_language' => $context->detectedLanguage,
            'fillers' => $fillers,
            'tool_metadata' => $metadata,
        ];
    }
}
