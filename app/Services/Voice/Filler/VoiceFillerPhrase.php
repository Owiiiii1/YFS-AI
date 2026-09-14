<?php

namespace App\Services\Voice\Filler;

final class VoiceFillerPhrase
{
    public function __construct(
        public readonly string $id,
        public readonly string $language,
        public readonly string $category,
        public readonly string $text,
    ) {}

    /**
     * @return array{phrase_id: string, language: string, category: string, text: string}
     */
    public function toArray(): array
    {
        return [
            'phrase_id' => $this->id,
            'language' => $this->language,
            'category' => $this->category,
            'text' => $this->text,
        ];
    }
}
