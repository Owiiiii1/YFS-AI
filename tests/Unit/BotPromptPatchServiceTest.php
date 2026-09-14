<?php

namespace Tests\Unit;

use App\Services\Bot\BotPromptPatchService;
use App\Support\DefaultBotPromptConfig;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BotPromptPatchServiceTest extends TestCase
{
    #[Test]
    public function it_builds_a_valid_preview_without_mutating_the_input(): void
    {
        $config = DefaultBotPromptConfig::make();
        $service = app(BotPromptPatchService::class);
        $original = $this->topicText($config, 'language');

        $preview = $service->preview($config, [[
            'path' => 'topics.language.text',
            'value' => $original."\nAlways preserve Ukrainian.",
        ]]);

        $this->assertSame($original, $this->topicText($config, 'language'));
        $this->assertNotSame($original, $this->topicText($preview['after'], 'language'));
        $this->assertSame([], $preview['sensitive']);
        $this->assertSame('topics.language.text', $preview['diff'][0]['path']);
    }

    #[Test]
    public function it_rejects_unknown_paths(): void
    {
        $this->expectException(ValidationException::class);

        app(BotPromptPatchService::class)->preview(DefaultBotPromptConfig::make(), [[
            'path' => 'system.shell_command',
            'value' => 'rm -rf /',
        ]]);
    }

    #[Test]
    public function it_can_patch_a_template_bound_to_a_topic(): void
    {
        $preview = app(BotPromptPatchService::class)->preview(DefaultBotPromptConfig::make(), [[
            'path' => 'topics.manager_handoff.templates.human_handoff.texts.en',
            'value' => 'A manager will continue this chat.',
        ]]);

        $this->assertSame(
            'A manager will continue this chat.',
            $this->templateText($preview['after'], 'human_handoff', 'en'),
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function topicText(array $config, string $id): string
    {
        foreach ($config['topics'] as $topic) {
            if (($topic['id'] ?? null) === $id) {
                return (string) ($topic['text'] ?? '');
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function templateText(array $config, string $id, string $language): string
    {
        foreach ($config['topics'] as $topic) {
            foreach ((array) ($topic['templates'] ?? []) as $template) {
                if (($template['id'] ?? null) === $id) {
                    return (string) ($template['texts'][$language] ?? '');
                }
            }
        }

        return '';
    }
}
