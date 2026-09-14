<?php

namespace App\Console\Commands;

use App\Models\BotPromptRevision;
use App\Models\BotSetting;
use App\Support\BotPromptSchema;
use App\Support\DefaultBotPromptConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncStructuredBotPromptDefaults extends Command
{
    protected $signature = 'bot:prompts:sync-defaults';

    protected $description = 'Add newly introduced default topics without overwriting admin-managed values';

    public function handle(): int
    {
        $changed = DB::transaction(function (): bool {
            $setting = BotSetting::query()->orderBy('id')->lockForUpdate()->firstOrFail();
            $current = BotPromptSchema::normalize((array) $setting->prompt_config);
            $defaults = DefaultBotPromptConfig::make();
            $existingIds = BotPromptSchema::sectionKeys($current);
            $added = false;

            foreach ($defaults['topics'] as $topic) {
                if (in_array((string) $topic['id'], $existingIds, true)) {
                    continue;
                }
                $current['topics'][] = $topic;
                $added = true;
            }

            foreach ($defaults['business_values'] as $key => $value) {
                if (! array_key_exists($key, (array) ($current['business_values'] ?? []))) {
                    $current['business_values'][$key] = $value;
                    $added = true;
                }
            }

            if (! $added) {
                return false;
            }

            BotPromptSchema::assertValid($current);
            BotPromptRevision::query()->create([
                'bot_setting_id' => $setting->id,
                'revision' => (int) $setting->prompt_revision,
                'prompt_config' => $setting->prompt_config,
                'reason' => 'Automatic snapshot before adding new structured config keys',
            ]);

            $setting->forceFill([
                'prompt_config' => $current,
                'prompt_revision' => (int) $setting->prompt_revision + 1,
            ])->save();

            return true;
        });

        $this->components->info($changed
            ? 'Missing default topics added; existing values preserved.'
            : 'Prompt config already contains all current default topics.');

        return self::SUCCESS;
    }
}
