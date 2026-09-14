<?php

namespace App\Services\Bot;

use App\Support\BotPromptSchema;
use Illuminate\Validation\ValidationException;

class BotPromptPatchService
{
    /**
     * @param  array<string, mixed>  $current
     * @param  list<array{path:string,value:mixed}>  $operations
     * @return array{after:array<string,mixed>,diff:list<array<string,mixed>>,sensitive:list<string>,valid:bool,validation:list<string>,validation_errors:list<string>}
     */
    public function preview(array $current, array $operations, bool $allowInvalidPreview = false): array
    {
        $after = $current;
        $diff = [];
        $sensitive = [];

        foreach ($operations as $index => $operation) {
            $path = trim((string) ($operation['path'] ?? ''));
            $resolved = $this->resolveWritablePath($after, $path);
            if ($resolved === null) {
                throw ValidationException::withMessages([
                    "operations.{$index}.path" => "Изменение пути [{$path}] запрещено.",
                ]);
            }
            if (! array_key_exists('value', $operation)) {
                throw ValidationException::withMessages([
                    "operations.{$index}.value" => 'Новое значение отсутствует.',
                ]);
            }

            $before = data_get($after, $resolved);
            $value = $operation['value'];
            if ($before === $value) {
                continue;
            }

            data_set($after, $resolved, $value);
            $diff[] = ['path' => $path, 'before' => $before, 'after' => $value];
        }

        if ($diff === []) {
            throw ValidationException::withMessages([
                'operations' => 'Предложение не содержит фактических изменений.',
            ]);
        }

        $validationErrors = [];
        try {
            BotPromptSchema::assertValid($after);
            $this->assertPlaceholdersValid($after);
        } catch (\Throwable $exception) {
            if (! $allowInvalidPreview) {
                throw $exception;
            }

            $validationErrors = $exception instanceof ValidationException
                ? collect($exception->errors())->flatten()->values()->all()
                : [$exception->getMessage()];
        }

        return [
            'after' => $after,
            'diff' => $diff,
            'sensitive' => array_values(array_unique($sensitive)),
            'valid' => $validationErrors === [],
            'validation' => $validationErrors === [] ? [
                'Схема prompt_config корректна.',
                'Темы и привязанные шаблоны сохранены.',
                'Плейсхолдеры распознаны.',
            ] : [],
            'validation_errors' => $validationErrors,
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function assertPlaceholdersValid(array $config): void
    {
        $allowed = array_merge(
            array_keys((array) ($config['business_values'] ?? [])),
            ['code', 'deposit', 'channel', 'dates', 'amount', 'expected', 'price', 'topic'],
        );
        $errors = [];

        $scan = function (string $text, string $path) use (&$errors, $allowed): void {
            preg_match_all('/\{\{([a-z0-9_]+)\}\}/i', $text, $matches);
            foreach (array_unique($matches[1] ?? []) as $placeholder) {
                if (! in_array($placeholder, $allowed, true)) {
                    $errors[$path][] = "Неизвестный плейсхолдер {{$placeholder}}.";
                }
            }
        };

        foreach (BotPromptSchema::topics($config) as $topic) {
            $topicId = (string) ($topic['id'] ?? 'topic');
            $scan((string) ($topic['text'] ?? ''), 'topics.'.$topicId.'.text');
            foreach ((array) ($topic['templates'] ?? []) as $template) {
                if (! is_array($template)) {
                    continue;
                }
                $templateId = (string) ($template['id'] ?? 'template');
                foreach ((array) ($template['texts'] ?? []) as $language => $text) {
                    $scan((string) $text, "topics.{$topicId}.templates.{$templateId}.texts.{$language}");
                }
            }
        }

        foreach ((array) ($config['sections'] ?? []) as $key => $text) {
            $scan((string) $text, 'sections.'.$key);
        }
        foreach ((array) ($config['templates'] ?? []) as $language => $templates) {
            foreach ((array) $templates as $key => $text) {
                $scan((string) $text, "templates.{$language}.{$key}");
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function resolveWritablePath(array $config, string $path): ?string
    {
        if (preg_match('/^topics\.([a-z][a-z0-9_]{1,63})\.text$/', $path, $match)) {
            $index = $this->topicIndex($config, $match[1]);

            return $index === null ? null : "topics.{$index}.text";
        }

        if (preg_match('/^topics\.([a-z][a-z0-9_]{1,63})\.labels\.(en|ru|uk)$/', $path, $match)) {
            $index = $this->topicIndex($config, $match[1]);

            return $index === null ? null : "topics.{$index}.labels.{$match[2]}";
        }

        if (preg_match('/^topics\.([a-z][a-z0-9_]{1,63})\.descriptions\.(en|ru|uk)$/', $path, $match)) {
            $index = $this->topicIndex($config, $match[1]);

            return $index === null ? null : "topics.{$index}.descriptions.{$match[2]}";
        }

        if (preg_match('/^topics\.([a-z][a-z0-9_]{1,63})\.templates\.([a-z][a-z0-9_]{1,63})\.texts\.(en|ru|uk)$/', $path, $match)) {
            $topicIndex = $this->topicIndex($config, $match[1]);
            if ($topicIndex === null) {
                return null;
            }
            $templateIndex = $this->templateIndex($config['topics'][$topicIndex] ?? [], $match[2]);

            return $templateIndex === null
                ? null
                : "topics.{$topicIndex}.templates.{$templateIndex}.texts.{$match[3]}";
        }

        if (preg_match('/^topics\.([a-z][a-z0-9_]{1,63})\.templates\.([a-z][a-z0-9_]{1,63})\.label$/', $path, $match)) {
            $topicIndex = $this->topicIndex($config, $match[1]);
            if ($topicIndex === null) {
                return null;
            }
            $templateIndex = $this->templateIndex($config['topics'][$topicIndex] ?? [], $match[2]);

            return $templateIndex === null
                ? null
                : "topics.{$topicIndex}.templates.{$templateIndex}.label";
        }

        if (preg_match('/^business_values\.(follow_up_delay_hours|business_hours_start|business_hours_end|business_timezone)$/', $path)) {
            return $path;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function topicIndex(array $config, string $id): ?int
    {
        foreach (array_values((array) ($config['topics'] ?? [])) as $index => $topic) {
            if (is_array($topic) && (string) ($topic['id'] ?? '') === $id) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $topic
     */
    private function templateIndex(array $topic, string $id): ?int
    {
        foreach (array_values((array) ($topic['templates'] ?? [])) as $index => $template) {
            if (is_array($template) && (string) ($template['id'] ?? '') === $id) {
                return $index;
            }
        }

        return null;
    }
}
