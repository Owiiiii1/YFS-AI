<?php

namespace App\Support;

use InvalidArgumentException;

final class BotPromptSchema
{
    public const VERSION = 2;

    /**
     * @return list<string>
     */
    public static function languages(): array
    {
        return ['en', 'ru', 'uk'];
    }

    /**
     * @param  array<string, mixed>|null  $config
     * @return list<string>
     */
    public static function sectionKeys(?array $config = null): array
    {
        if ($config === null) {
            return [];
        }

        return collect($config['topics'] ?? [])
            ->filter(fn (mixed $topic): bool => is_array($topic) && filled($topic['id'] ?? null))
            ->map(fn (array $topic): string => (string) $topic['id'])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>|null  $config
     * @return list<string>
     */
    public static function templateKeys(?array $config = null): array
    {
        if ($config === null) {
            return [];
        }

        return collect($config['topics'] ?? [])
            ->flatMap(fn (mixed $topic): array => is_array($topic) ? (array) ($topic['templates'] ?? []) : [])
            ->filter(fn (mixed $template): bool => is_array($template) && filled($template['id'] ?? null))
            ->map(fn (array $template): string => (string) $template['id'])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<array<string, mixed>>
     */
    public static function topics(array $config): array
    {
        return collect($config['topics'] ?? [])
            ->filter(fn (mixed $topic): bool => is_array($topic))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public static function normalize(array $config): array
    {
        if (($config['schema_version'] ?? null) === self::VERSION && self::looksLikeCurrent($config)) {
            return $config;
        }

        return DefaultBotPromptConfig::fromLegacy($config);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function assertValid(array $config): void
    {
        if (($config['schema_version'] ?? null) !== self::VERSION) {
            throw new InvalidArgumentException('Unsupported bot prompt schema version.');
        }

        $topics = $config['topics'] ?? null;
        if (! is_array($topics) || $topics === []) {
            throw new InvalidArgumentException('Prompt config must contain at least one topic.');
        }

        $topicIds = [];
        $templateIds = [];

        foreach (array_values($topics) as $index => $topic) {
            if (! is_array($topic)) {
                throw new InvalidArgumentException("Topic [{$index}] is invalid.");
            }

            $id = (string) ($topic['id'] ?? '');
            if (! self::isStableId($id)) {
                throw new InvalidArgumentException("Topic [{$index}] has an invalid id.");
            }
            if (isset($topicIds[$id])) {
                throw new InvalidArgumentException("Duplicate topic id [{$id}].");
            }
            $topicIds[$id] = true;

            if (! self::hasLabel($topic)) {
                throw new InvalidArgumentException("Topic [{$id}] is missing a label.");
            }

            if (! array_key_exists('text', $topic) || ! is_string($topic['text'])) {
                throw new InvalidArgumentException("Topic [{$id}] is missing text.");
            }

            if (array_key_exists('always_include', $topic) && ! is_bool($topic['always_include'])) {
                throw new InvalidArgumentException("Topic [{$id}] always_include must be a boolean.");
            }

            $templates = $topic['templates'] ?? [];
            if (! is_array($templates)) {
                throw new InvalidArgumentException("Topic [{$id}] templates must be an array.");
            }

            foreach (array_values($templates) as $templateIndex => $template) {
                if (! is_array($template)) {
                    throw new InvalidArgumentException("Template [{$id}.{$templateIndex}] is invalid.");
                }

                $templateId = (string) ($template['id'] ?? '');
                if (! self::isStableId($templateId)) {
                    throw new InvalidArgumentException("Template [{$id}.{$templateIndex}] has an invalid id.");
                }
                if (isset($templateIds[$templateId])) {
                    throw new InvalidArgumentException("Duplicate template id [{$templateId}].");
                }
                $templateIds[$templateId] = true;

                if (! filled($template['label'] ?? null) && ! self::hasLocalizedString($template['labels'] ?? null)) {
                    throw new InvalidArgumentException("Template [{$templateId}] is missing a label.");
                }

                $texts = $template['texts'] ?? null;
                if (! is_array($texts)) {
                    throw new InvalidArgumentException("Template [{$templateId}] is missing texts.");
                }

                foreach (self::languages() as $language) {
                    if (! array_key_exists($language, $texts) || ! is_string($texts[$language])) {
                        throw new InvalidArgumentException("Template [{$templateId}] is missing [{$language}] text.");
                    }
                }
            }
        }
    }

    public static function isStableId(string $id): bool
    {
        return (bool) preg_match('/^[a-z][a-z0-9_]{1,63}$/', $id);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function looksLikeCurrent(array $config): bool
    {
        $topics = $config['topics'] ?? null;

        return is_array($topics) && $topics !== [];
    }

    /**
     * @param  array<string, mixed>  $topic
     */
    private static function hasLabel(array $topic): bool
    {
        return filled($topic['label'] ?? null) || self::hasLocalizedString($topic['labels'] ?? null);
    }

    private static function hasLocalizedString(mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }

        foreach (self::languages() as $language) {
            if (isset($value[$language]) && is_string($value[$language]) && trim($value[$language]) !== '') {
                return true;
            }
        }

        return false;
    }
}
