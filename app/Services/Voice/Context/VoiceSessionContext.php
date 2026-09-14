<?php

namespace App\Services\Voice\Context;

final class VoiceSessionContext
{
    /**
     * @param  array<string, mixed>|null  $customerContext
     * @param  array<string, mixed>|null  $yfsContext
     * @param  array<string, mixed>|null  $bitrixContext
     * @param  array<string, mixed>  $freshness
     */
    public function __construct(
        public readonly string $sessionId,
        public readonly ?string $callerPhone,
        public readonly string $detectedLanguage,
        public readonly ?array $customerContext,
        public readonly ?array $yfsContext,
        public readonly ?array $bitrixContext,
        public readonly ?string $currentTopic,
        public readonly string $conversationState,
        public readonly string $loadedAt,
        public readonly array $freshness,
    ) {}

    /**
     * @return list<string>
     */
    public function sectionNames(): array
    {
        $sections = ['session'];
        if ($this->customerContext !== null) {
            $sections[] = 'customer';
        }
        if ($this->yfsContext !== null) {
            $sections[] = 'yfs';
        }
        if ($this->bitrixContext !== null) {
            $sections[] = 'bitrix';
        }

        return $sections;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'session_id' => $this->sessionId,
            'caller_phone' => $this->callerPhone,
            'detected_language' => $this->detectedLanguage,
            'customer_context' => $this->customerContext,
            'yfs_context' => $this->yfsContext,
            'bitrix_context' => $this->bitrixContext,
            'current_topic' => $this->currentTopic,
            'conversation_state' => $this->conversationState,
            'loaded_at' => $this->loadedAt,
            'freshness' => $this->freshness,
        ];
    }
}
