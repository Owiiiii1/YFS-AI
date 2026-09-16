<?php

namespace Tests\Unit\Voice;

use App\Services\Voice\Identity\CustomerIdentityResult;
use App\Services\Voice\Prompt\VoiceAssistantPromptBuilder;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VoiceAssistantPromptBuilderTest extends TestCase
{
    #[Test]
    public function enabled_sections_are_included_in_sort_order_and_disabled_are_omitted(): void
    {
        $builder = new VoiceAssistantPromptBuilder;

        $assembled = $builder->assemble([
            [
                'key' => 'payments',
                'title' => 'Payments',
                'instructions' => 'Do not invent payment methods.',
                'sort_order' => 10,
            ],
            [
                'key' => 'general',
                'title' => 'General rules',
                'instructions' => 'Use the app first.',
                'sort_order' => 1,
            ],
        ]);

        $paymentsPos = strpos($assembled->prompt, '## Payments');
        $generalPos = strpos($assembled->prompt, '## General rules');

        $this->assertNotFalse($generalPos);
        $this->assertNotFalse($paymentsPos);
        $this->assertTrue($generalPos < $paymentsPos);
        $this->assertStringContainsString('Use the app first.', $assembled->prompt);
        $this->assertStringContainsString('Do not invent payment methods.', $assembled->prompt);
        $this->assertStringContainsString('YFS) Customer Support Voice Assistant', $assembled->prompt);
        $this->assertStringNotContainsString('Backstage secret', $assembled->prompt);
    }

    #[Test]
    public function section_body_is_not_rewritten(): void
    {
        $builder = new VoiceAssistantPromptBuilder;
        $body = "Exact client wording.\nSecond line.";

        $assembled = $builder->assemble([
            [
                'key' => 'general',
                'title' => 'General rules',
                'instructions' => $body,
                'sort_order' => 1,
            ],
        ]);

        $this->assertStringContainsString($body, $assembled->prompt);
    }

    #[Test]
    public function identical_sections_produce_identical_versions_and_instruction_changes_do_not(): void
    {
        $builder = new VoiceAssistantPromptBuilder;
        $sections = [[
            'key' => 'general',
            'title' => 'General rules',
            'instructions' => 'Stable policy.',
            'sort_order' => 1,
        ]];

        $first = $builder->assemble($sections, Carbon::parse('2026-09-15T10:00:00Z'));
        $second = $builder->assemble($sections, Carbon::parse('2026-09-15T11:00:00Z'));
        $changed = $builder->assemble([[
            'key' => 'general',
            'title' => 'General rules',
            'instructions' => 'Updated policy.',
            'sort_order' => 1,
        ]]);

        $this->assertSame($first->version, $second->version);
        $this->assertSame($first->prompt, $second->prompt);
        $this->assertNotSame($first->generatedAt, $second->generatedAt);
        $this->assertNotSame($first->version, $changed->version);
        $this->assertStringStartsWith('v5-', $first->version);
        $this->assertMatchesRegularExpression('/^v5-[a-f0-9]{64}$/', $first->version);
    }

    #[Test]
    public function runtime_decision_rules_are_in_the_immutable_wrapper(): void
    {
        $builder = new VoiceAssistantPromptBuilder;
        $assembled = $builder->assemble([
            [
                'key' => 'general',
                'title' => 'General rules',
                'instructions' => 'Exact client wording.',
                'sort_order' => 1,
            ],
        ]);

        $prompt = $assembled->prompt;
        $this->assertStringContainsString('A. KNOWN POLICY FACT', $prompt);
        $this->assertStringContainsString('answer yourself', $prompt);
        $this->assertStringContainsString('B. MISSING DYNAMIC FACT', $prompt);
        $this->assertStringContainsString('not automatic escalation', $prompt);
        $this->assertStringContainsString('C. HUMAN REQUIRED', $prompt);
        $this->assertStringContainsString('collect contact details only when', $prompt);
        $this->assertStringContainsString('Do not invent dynamic facts', $prompt);
        $this->assertStringContainsString('D. LIVE SHOW TOOLS', $prompt);
        $this->assertStringContainsString('get_public_shows', $prompt);
        $this->assertStringContainsString('get_show_brands', $prompt);
        $this->assertStringContainsString('Tool results override static policy for live show facts', $prompt);
        $this->assertStringContainsString('E. CALLER IDENTITY', $prompt);
        $this->assertStringContainsString('resolve_customer_identity', $prompt);
        $this->assertStringContainsString('Do not call resolve_customer_identity for them', $prompt);
        $this->assertStringContainsString('Подскажите, пожалуйста, ваше имя и фамилию.', $prompt);
        $this->assertStringContainsString('ask for the child’s first name', $prompt);
        $this->assertStringContainsString('already uniquely established', $prompt);
        $this->assertStringContainsString('Exact client wording.', $prompt);
        $this->assertStringStartsWith('v5-', $assembled->version);
    }

    #[Test]
    public function wrapper_version_is_part_of_the_prompt_version(): void
    {
        $builder = new VoiceAssistantPromptBuilder;
        $assembled = $builder->assemble([]);

        $this->assertMatchesRegularExpression('/^v5-[a-f0-9]{64}$/', $assembled->version);
        $this->assertStringContainsString('KNOWN POLICY FACT', $assembled->prompt);
        $this->assertStringContainsString('get_public_shows', $assembled->prompt);
        $this->assertStringContainsString('get_show_brands', $assembled->prompt);
        $this->assertStringContainsString('E. CALLER IDENTITY', $assembled->prompt);
    }

    #[Test]
    public function caller_identity_changes_prompt_text_but_not_version_hash(): void
    {
        $builder = new VoiceAssistantPromptBuilder;
        $sections = [[
            'key' => 'general',
            'title' => 'General rules',
            'instructions' => 'Stable policy.',
            'sort_order' => 1,
        ]];

        $unknown = $builder->assemble($sections, identity: CustomerIdentityResult::notFound('phone'));
        $unique = $builder->assemble(
            $sections,
            identity: CustomerIdentityResult::unique('phone', 77, 'Test Parent', 'en'),
        );
        $plain = $builder->assemble($sections);

        $this->assertSame($plain->version, $unknown->version);
        $this->assertSame($plain->version, $unique->version);
        $this->assertStringContainsString('Status: identified.', $unique->prompt);
        $this->assertStringContainsString('Test Parent', $unique->prompt);
        $this->assertStringContainsString('Do not call resolve_customer_identity for an ordinary question', $unique->prompt);
        $this->assertStringNotContainsString('77', $unique->prompt);
        $this->assertStringNotContainsString('Status: identified.', $plain->prompt);
        $this->assertStringContainsString('ask for first and last name, then call resolve_customer_identity', $unknown->prompt);
    }

    #[Test]
    public function public_questions_do_not_require_identity_and_unique_callers_are_not_reidentified(): void
    {
        $builder = new VoiceAssistantPromptBuilder;
        $prompt = $builder->assemble([])->prompt;

        $this->assertStringContainsString('Public show questions', $prompt);
        $this->assertStringContainsString('get_public_shows / get_show_brands', $prompt);
        $this->assertStringContainsString('Do not call resolve_customer_identity for them', $prompt);
        $this->assertStringContainsString('not already uniquely established', $prompt);
        $this->assertStringContainsString('different registered parent or family', $prompt);
        $this->assertStringContainsString('ask_child_name', $prompt);
    }
}
