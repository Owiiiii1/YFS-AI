<?php

namespace App\Console\Commands;

use App\Models\BotSetting;
use App\Support\BotPromptSchema;
use App\Support\DefaultBotPromptConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillStructuredBotPrompts extends Command
{
    protected $signature = 'bot:prompts:backfill {--force : Replace an existing structured config}';

    protected $description = 'Install the default structured topic config when none is active';

    public function handle(): int
    {
        DB::transaction(function (): void {
            $setting = BotSetting::query()->orderBy('id')->lockForUpdate()->firstOrFail();
            $config = is_array($setting->prompt_config) ? $setting->prompt_config : [];

            if (($config['schema_version'] ?? null) === BotPromptSchema::VERSION
                && is_array($config['topics'] ?? null)
                && $config['topics'] !== []
                && ! $this->option('force')) {
                $this->components->info('Structured prompt config already exists; nothing changed.');

                return;
            }

            $next = $config === []
                ? DefaultBotPromptConfig::make()
                : BotPromptSchema::normalize($config);

            $setting->forceFill([
                'prompt_config' => $next,
                'prompt_revision' => max(1, (int) $setting->prompt_revision),
                'structured_prompts_ready' => true,
            ])->save();

            $this->components->info('Structured prompt topics are ready.');
        });

        return self::SUCCESS;
    }
}
