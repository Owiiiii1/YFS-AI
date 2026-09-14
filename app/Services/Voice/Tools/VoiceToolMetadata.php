<?php

namespace App\Services\Voice\Tools;

final class VoiceToolMetadata
{
    public function __construct(
        public readonly string $category,
        public readonly string $estimatedLatency,
        public readonly bool $fillerEnabled,
        public readonly bool $readOnly,
        public readonly string $source,
    ) {}

    public static function defaults(): self
    {
        return new self(
            category: 'lookup',
            estimatedLatency: 'short',
            fillerEnabled: false,
            readOnly: true,
            source: 'unknown',
        );
    }

    /**
     * @return array{category: string, estimated_latency: string, filler_enabled: bool, read_only: bool, source: string}
     */
    public function toArray(): array
    {
        return [
            'category' => $this->category,
            'estimated_latency' => $this->estimatedLatency,
            'filler_enabled' => $this->fillerEnabled,
            'read_only' => $this->readOnly,
            'source' => $this->source,
        ];
    }
}
