<?php

namespace Tests\Unit;

use App\Support\BotPromptSchema;
use App\Support\DefaultBotPromptConfig;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class BotPromptSchemaTest extends TestCase
{
    public function test_default_config_has_editable_topics_and_bound_templates(): void
    {
        $config = DefaultBotPromptConfig::make();

        BotPromptSchema::assertValid($config);

        $this->assertSame(BotPromptSchema::VERSION, $config['schema_version']);
        $this->assertSame(
            DefaultBotPromptConfig::keptLegacyTopicIds(),
            BotPromptSchema::sectionKeys($config),
        );
        $this->assertContains('human_handoff', BotPromptSchema::templateKeys($config));
    }

    public function test_config_without_topics_is_rejected(): void
    {
        $config = DefaultBotPromptConfig::make();
        $config['topics'] = [];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Prompt config must contain at least one topic.');

        BotPromptSchema::assertValid($config);
    }

    public function test_legacy_bakery_sections_are_dropped_on_normalize(): void
    {
        $legacy = [
            'schema_version' => 1,
            'sections' => [
                'role_brand' => 'You represent YoungFashionShow.',
                'flavors' => 'Cake catalog must not survive.',
                'pricing' => 'Price per lb must not survive.',
            ],
            'business_values' => [
                'price_per_lb' => 30,
                'business_timezone' => 'America/Chicago',
            ],
            'templates' => [
                'en' => ['human_handoff' => 'Legacy handoff'],
                'ru' => ['human_handoff' => 'Старый handoff'],
                'uk' => ['human_handoff' => 'Старий handoff'],
            ],
        ];

        $config = BotPromptSchema::normalize($legacy);

        BotPromptSchema::assertValid($config);
        $this->assertSame(2, $config['schema_version']);
        $this->assertSame('You represent YoungFashionShow.', $this->topicText($config, 'role_brand'));
        $this->assertNull($this->findTopic($config, 'flavors'));
        $this->assertNull($this->findTopic($config, 'pricing'));
        $this->assertSame('Legacy handoff', $this->templateText($config, 'human_handoff', 'en'));
        $this->assertSame('America/Chicago', $config['business_values']['business_timezone']);
        $this->assertArrayNotHasKey('price_per_lb', $config['business_values']);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>|null
     */
    private function findTopic(array $config, string $id): ?array
    {
        foreach ($config['topics'] as $topic) {
            if (($topic['id'] ?? null) === $id) {
                return $topic;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function topicText(array $config, string $id): string
    {
        return (string) ($this->findTopic($config, $id)['text'] ?? '');
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
