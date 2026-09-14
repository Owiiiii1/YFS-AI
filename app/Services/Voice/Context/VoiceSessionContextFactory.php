<?php

namespace App\Services\Voice\Context;

use App\Services\Voice\Prompt\VoiceLanguageDetector;
use App\Services\Voice\Prompt\VoiceTopicResolver;
use Illuminate\Support\Facades\Log;

final class VoiceSessionContextFactory
{
    public function __construct(
        private readonly VoiceLanguageDetector $languageDetector,
        private readonly VoiceTopicResolver $topicResolver,
    ) {}

    public function make(
        string $sessionId,
        string $userText,
        ?string $callerPhone = null,
        ?string $languageHint = null,
    ): VoiceSessionContext {
        $started = hrtime(true);
        $language = $this->languageDetector->detect($userText, $languageHint);
        $topic = $this->topicResolver->resolve($userText);
        $loadedAt = gmdate('c');

        $context = new VoiceSessionContext(
            sessionId: $sessionId,
            callerPhone: $callerPhone,
            detectedLanguage: $language,
            customerContext: null,
            yfsContext: [
                'event_name' => 'YFS Test Event',
                'message' => 'Voice session context is working.',
                'availability' => 'test-only',
                'source' => 'yfs_ai_test',
                'note' => 'Synthetic preloaded test-only data. Not a production show, customer, or CRM record.',
            ],
            bitrixContext: null,
            currentTopic: $topic,
            conversationState: 'active',
            loadedAt: $loadedAt,
            freshness: [
                'yfs' => 'synthetic_preload',
                'bitrix' => 'absent',
                'customer' => 'absent',
            ],
        );

        Log::info('voice.session_context.loaded', [
            'session_id' => $sessionId,
            'sections' => $context->sectionNames(),
            'latency_ms' => (int) round((hrtime(true) - $started) / 1_000_000),
        ]);

        return $context;
    }
}
