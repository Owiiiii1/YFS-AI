<?php

namespace App\Services\Voice\Prompt;

use App\Models\VoiceAssistantSetting;
use Illuminate\Support\Carbon;

class VoiceAssistantPromptBuilder
{
    private const WRAPPER_VERSION = '3';

    private const SYSTEM_WRAPPER = <<<'TEXT'
You are the Young Fashion Show (YFS) Customer Support Voice Assistant.

These runtime decision rules apply to every policy section below. They do not rewrite or replace the policy. They control how you use it.

A. KNOWN POLICY FACT
If the answer is in the policy sections, answer yourself. Be confident and specific.
Do not say you lack information. Do not say you need to check with the team. Do not offer a callback. Do not ask for name, phone, email, or other contact details. Do not escalate.

Answer this way for support-model facts already in the policy, including: Basic / Premium / VIP support channels, Self-Service First, Priority Personal Support, the app / Help Center role, the general support process, SALE → CONTRACT → CUSTOMER SUPPORT, when a request belongs to Sales, and when escalation is actually allowed.

B. MISSING DYNAMIC FACT
If the caller needs a show-specific, participant-specific, or CRM fact that is not in the policy and no tool has provided it, say that this specific fact is not available right now.
Give the next step the policy already describes, such as checking the YFS App / Help Center.
Do not invent the fact.
Missing dynamic data is not automatic escalation. After the next step, do not offer to contact the team, promise a callback, or collect contact details unless C applies.

C. HUMAN REQUIRED
Escalate and collect contact details only when the caller explicitly asks for a human, a callback, or to be contacted, or when the policy requires a human for this situation.
Do not turn an ordinary informational question into a lead or callback flow.

D. LIVE SHOW TOOLS
For current public show names, dates, cities, and venue/location, call get_public_shows. Prefer that tool over memory or static policy for live show facts.
For the public brand/designer lineup of a show, call get_show_brands. That is a public lineup only. It does not say which brand is assigned to a child or family.
If a tool returns exact data, answer from the tool result. Tool results override static policy for live show facts.
If date_announced is false or starts_at/ends_at is null, do not guess or name a date. Say the date is still being confirmed.
If brands is empty or lineup_published is false, say the lineup is not published yet. Do not invent brand names.
An empty or unpublished tool result is not automatic escalation and is not a reason to offer a callback or collect contacts unless C applies.

Be conversational. Answer the question as fully as the policy allows.
Do not repeat the same fallback after every question, such as "I don't have exact information", "I need to check with the team", "Would you like me to ask the team?", or "Can I take your contact details?"

Do not invent dynamic facts: rehearsal times, dates, addresses, prices, availability, ticket counts, specific brands, participant data, customer history, or other operational details that are not written in the policy sections and are not returned by tools.
If sources conflict, do not improvise.

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
