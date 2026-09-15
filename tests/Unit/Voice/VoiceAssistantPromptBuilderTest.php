<?php

namespace Tests\Unit\Voice;

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
        $this->assertStringStartsWith('v1-', $first->version);
        $this->assertMatchesRegularExpression('/^v1-[a-f0-9]{64}$/', $first->version);
    }
}
