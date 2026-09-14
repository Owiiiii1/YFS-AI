<?php

namespace App\Models;

use App\Support\BotPromptSchema;
use App\Support\DefaultBotPromptConfig;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'bot_enabled',
    'main_prompt_path',
    'main_prompt_original_name',
    'main_prompt_text',
    'business_rules',
    'additional_prompts',
    'prompt_config',
    'prompt_revision',
    'structured_prompts_ready',
    'manual_mode_reenable_days',
])]
class BotSetting extends Model
{
    public static function instance(): self
    {
        $setting = self::query()->orderBy('id')->first();

        if (! $setting) {
            return self::query()->create(self::freshStructuredDefaults());
        }

        $config = is_array($setting->prompt_config) ? $setting->prompt_config : [];
        if (! self::hasCurrentStructuredConfig($config)) {
            $normalized = $config === []
                ? DefaultBotPromptConfig::make()
                : BotPromptSchema::normalize($config);

            $setting->forceFill([
                'prompt_config' => $normalized,
                'prompt_revision' => max(1, (int) $setting->prompt_revision + ($config === [] ? 0 : 1)),
                'structured_prompts_ready' => true,
            ])->save();
        }

        return $setting;
    }

    /**
     * @return array<string, mixed>
     */
    private static function freshStructuredDefaults(): array
    {
        return [
            'bot_enabled' => true,
            'additional_prompts' => [],
            'prompt_config' => DefaultBotPromptConfig::make(),
            'prompt_revision' => 1,
            'structured_prompts_ready' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function hasCurrentStructuredConfig(array $config): bool
    {
        return ($config['schema_version'] ?? null) === BotPromptSchema::VERSION
            && is_array($config['topics'] ?? null)
            && $config['topics'] !== [];
    }

    /**
     * @return list<array{id: string, name: string, user_hint: string, text: string}>
     */
    public function additionalPromptsList(): array
    {
        $prompts = $this->additional_prompts;

        if (! is_array($prompts)) {
            return [];
        }

        return collect($prompts)
            ->filter(fn ($item): bool => is_array($item) && filled($item['name'] ?? null))
            ->map(fn (array $item): array => [
                'id' => (string) ($item['id'] ?? ''),
                'name' => (string) ($item['name'] ?? ''),
                'user_hint' => (string) ($item['user_hint'] ?? ''),
                'text' => (string) ($item['text'] ?? ''),
            ])
            ->values()
            ->all();
    }

    public function promptRevisions(): HasMany
    {
        return $this->hasMany(BotPromptRevision::class);
    }

    protected function casts(): array
    {
        return [
            'bot_enabled' => 'boolean',
            'additional_prompts' => 'array',
            'prompt_config' => 'array',
            'prompt_revision' => 'integer',
            'structured_prompts_ready' => 'boolean',
            'manual_mode_reenable_days' => 'integer',
        ];
    }
}
