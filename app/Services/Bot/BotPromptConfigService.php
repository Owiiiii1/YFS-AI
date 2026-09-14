<?php

namespace App\Services\Bot;

use App\Models\BotSetting;
use App\Support\BotPromptSchema;
use InvalidArgumentException;

class BotPromptConfigService
{
    /** @var array<string, mixed>|null */
    private ?array $snapshot = null;

    private ?int $snapshotRevision = null;

    /**
     * @return array<string, mixed>|null
     */
    public function active(?BotSetting $setting = null): ?array
    {
        $setting ??= BotSetting::instance();

        if (! $setting->structured_prompts_ready || ! is_array($setting->prompt_config)) {
            return null;
        }

        if ($this->snapshot !== null && $this->snapshotRevision === (int) $setting->prompt_revision) {
            return $this->snapshot;
        }

        BotPromptSchema::assertValid($setting->prompt_config);

        $this->snapshot = $setting->prompt_config;
        $this->snapshotRevision = (int) $setting->prompt_revision;

        return $this->snapshot;
    }

    public function isActive(?BotSetting $setting = null): bool
    {
        return $this->active($setting) !== null;
    }

    public function section(string $key, ?BotSetting $setting = null): string
    {
        $config = $this->active($setting);
        if ($config === null) {
            return '';
        }

        $topic = $this->findTopic($config, $key);
        if ($topic === null) {
            return $this->interpolate((string) data_get($config, 'sections.'.$key, ''), $config);
        }

        return $this->interpolate((string) ($topic['text'] ?? ''), $config);
    }

    /**
     * @param  list<string>  $extraTopicIds
     * @return list<string>
     */
    public function sectionsForReply(?BotSetting $setting = null, string $language = 'en', array $extraTopicIds = []): array
    {
        $config = $this->active($setting);
        if ($config === null) {
            return [];
        }

        $language = in_array($language, BotPromptSchema::languages(), true) ? $language : 'en';
        $wanted = [];
        foreach ($extraTopicIds as $id) {
            if (is_string($id) && $id !== '') {
                $wanted[$id] = true;
            }
        }

        $parts = [];

        foreach (BotPromptSchema::topics($config) as $topic) {
            $id = (string) ($topic['id'] ?? '');
            $always = (bool) ($topic['always_include'] ?? false);
            if (! $always && ! isset($wanted[$id])) {
                continue;
            }

            $text = $this->interpolate((string) ($topic['text'] ?? ''), $config);
            $templates = $this->topicTemplatesBlock($topic, $language, $config);
            if ($text === '' && $templates === '') {
                continue;
            }

            $heading = strtoupper((string) (
                $topic['labels']['en']
                ?? $topic['label']
                ?? $id
                ?? 'topic'
            ));
            $block = $heading.':';
            if ($text !== '') {
                $block .= "\n".$text;
            }
            if ($templates !== '') {
                $block .= "\n\nFIXED CUSTOMER MESSAGES:\n".$templates;
            }
            $parts[] = $block;
        }

        return $parts;
    }

    /**
     * @return list<string>
     */
    public function alwaysIncludedSections(?BotSetting $setting = null, string $language = 'en'): array
    {
        return $this->sectionsForReply($setting, $language, []);
    }

    public function value(string $key, mixed $default = null, ?BotSetting $setting = null): mixed
    {
        $config = $this->active($setting);
        if ($config === null) {
            return $default;
        }

        return data_get($config, 'business_values.'.$key, $default);
    }

    /**
     * @return list<array{guests:int,weight_lb:float}>
     */
    public function weightGuide(?BotSetting $setting = null): array
    {
        $raw = $this->value('weight_guide', [], $setting);
        if (! is_array($raw)) {
            return [];
        }

        return collect($raw)
            ->filter(fn (mixed $row): bool => is_array($row)
                && is_numeric($row['guests'] ?? null)
                && is_numeric($row['weight_lb'] ?? null))
            ->map(fn (array $row): array => [
                'guests' => (int) $row['guests'],
                'weight_lb' => (float) $row['weight_lb'],
            ])
            ->sortBy('guests')
            ->values()
            ->all();
    }

    public function weightGuideText(?BotSetting $setting = null): string
    {
        return collect($this->weightGuide($setting))
            ->map(fn (array $row): string => $row['guests'].' guests → '.$this->number($row['weight_lb']).' lb')
            ->implode(' | ');
    }

    /**
     * @param  array<string, scalar|null>  $variables
     */
    public function template(string $key, string $language, array $variables = [], ?BotSetting $setting = null): string
    {
        $config = $this->active($setting);
        if ($config === null) {
            throw new InvalidArgumentException('Structured bot prompt configuration is not active.');
        }

        $language = in_array($language, BotPromptSchema::languages(), true) ? $language : 'en';
        $template = $this->findTemplateText($config, $key, $language);
        if ($template === '') {
            return '';
        }

        $values = array_merge(
            collect((array) ($config['business_values'] ?? []))
                ->filter(fn (mixed $value): bool => is_scalar($value) || $value === null)
                ->all(),
            $variables,
        );

        return $this->replacePlaceholders($template, $values);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>|null
     */
    private function findTopic(array $config, string $id): ?array
    {
        foreach (BotPromptSchema::topics($config) as $topic) {
            if ((string) ($topic['id'] ?? '') === $id) {
                return $topic;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function findTemplateText(array $config, string $key, string $language): string
    {
        foreach (BotPromptSchema::topics($config) as $topic) {
            foreach ((array) ($topic['templates'] ?? []) as $template) {
                if (! is_array($template) || (string) ($template['id'] ?? '') !== $key) {
                    continue;
                }

                $texts = (array) ($template['texts'] ?? []);
                $text = (string) ($texts[$language] ?? '');
                if ($text === '') {
                    $text = (string) ($texts['en'] ?? '');
                }

                return $text;
            }
        }

        $legacy = (string) data_get($config, "templates.{$language}.{$key}", '');
        if ($legacy === '') {
            $legacy = (string) data_get($config, "templates.en.{$key}", '');
        }

        return $legacy;
    }

    /**
     * @param  array<string, mixed>  $topic
     * @param  array<string, mixed>  $config
     */
    private function topicTemplatesBlock(array $topic, string $language, array $config): string
    {
        $lines = [];
        foreach ((array) ($topic['templates'] ?? []) as $template) {
            if (! is_array($template)) {
                continue;
            }

            $id = (string) ($template['id'] ?? '');
            $label = (string) ($template['label'] ?? $id);
            $text = $this->interpolate((string) data_get($template, "texts.{$language}", data_get($template, 'texts.en', '')), $config);
            if ($id === '' || $text === '') {
                continue;
            }

            $lines[] = '['.$id.'] '.$label.":\n".$text;
        }

        return implode("\n\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function interpolate(string $text, array $config): string
    {
        $values = collect((array) ($config['business_values'] ?? []))
            ->filter(fn (mixed $value): bool => is_scalar($value) || $value === null)
            ->all();
        $values = array_merge($values, \App\Support\BotFormLinks::placeholderValues($config));

        return trim($this->replacePlaceholders($text, $values));
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function replacePlaceholders(string $text, array $values): string
    {
        foreach ($values as $key => $value) {
            $text = str_replace('{{'.$key.'}}', (string) $value, $text);
        }

        return $text;
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
