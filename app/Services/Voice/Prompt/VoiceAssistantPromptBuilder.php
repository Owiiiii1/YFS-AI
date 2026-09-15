<?php

namespace App\Services\Voice\Prompt;

use App\Models\VoiceAssistantSetting;
use Illuminate\Support\Carbon;

class VoiceAssistantPromptBuilder
{
    private const WRAPPER_VERSION = '1';

    private const SYSTEM_WRAPPER = <<<'TEXT'
You are the Young Fashion Show (YFS) Customer Support Voice Assistant.

Use only the policy sections provided below.
Do not invent missing business facts such as times, addresses, ticket counts, brands, prices, or other operational details that are not written in these sections.
Current show-specific data will be provided later through tools. If a tool result is not available, do not guess.
If sources conflict, do not improvise.
Follow the escalation rules in the policy sections.

POLICY SECTIONS
TEXT;

    /**
     * Assemble the current enabled admin settings into a runtime prompt.
     */
    public function build(?Carbon $generatedAt = null): VoiceAssistantRuntimePrompt
    {
        $sections = VoiceAssistantSetting::query()
            ->where('enabled', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['key', 'title', 'instructions', 'sort_order'])
            ->map(fn (VoiceAssistantSetting $section): array => [
                'key' => (string) $section->key,
                'title' => (string) $section->title,
                'instructions' => (string) ($section->instructions ?? ''),
                'sort_order' => (int) $section->sort_order,
            ])
            ->all();

        return $this->assemble($sections, $generatedAt);
    }

    /**
     * @param  list<array{key: string, title: string, instructions: string, sort_order: int}>  $sections
     */
    public function assemble(array $sections, ?Carbon $generatedAt = null): VoiceAssistantRuntimePrompt
    {
        $sections = $this->sorted($sections);
        $prompt = self::SYSTEM_WRAPPER;

        if ($sections === []) {
            $prompt .= "\n\nNo enabled policy sections are currently configured.";
        } else {
            foreach ($sections as $section) {
                $title = $section['title'];
                $instructions = $section['instructions'];
                $prompt .= "\n\n## ".$title."\n\n".$instructions;
            }
        }

        return new VoiceAssistantRuntimePrompt(
            prompt: $prompt,
            version: $this->versionFor($sections),
            generatedAt: ($generatedAt ?? now())->utc()->toIso8601String(),
        );
    }

    /**
     * @param  list<array{key: string, title: string, instructions: string, sort_order: int}>  $sections
     */
    public function versionFor(array $sections): string
    {
        $sections = $this->sorted($sections);
        $canonical = [];
        foreach ($sections as $section) {
            $canonical[] = [
                'key' => $section['key'],
                'title' => $section['title'],
                'instructions' => $section['instructions'],
                'sort_order' => $section['sort_order'],
            ];
        }

        $payload = json_encode(
            [
                'wrapper' => self::WRAPPER_VERSION,
                'sections' => $canonical,
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        return 'v'.self::WRAPPER_VERSION.'-'.hash('sha256', (string) $payload);
    }

    /**
     * @param  list<array{key: string, title: string, instructions: string, sort_order: int}>  $sections
     * @return list<array{key: string, title: string, instructions: string, sort_order: int}>
     */
    private function sorted(array $sections): array
    {
        usort($sections, function (array $left, array $right): int {
            return [$left['sort_order'], $left['key']] <=> [$right['sort_order'], $right['key']];
        });

        return array_values($sections);
    }
}
