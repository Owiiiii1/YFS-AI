<?php

namespace App\Console\Commands;

use App\Models\BotPromptRevision;
use App\Models\BotSetting;
use App\Support\DefaultBotPromptConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ResetYfsBotPrompts extends Command
{
    protected $signature = 'bot:prompts:reset-yfs';

    protected $description = 'Replace the live structured prompt with the YFS topic set';

    public function handle(): int
    {
        DB::transaction(function (): void {
            $setting = BotSetting::query()->orderBy('id')->lockForUpdate()->firstOrFail();
            $next = DefaultBotPromptConfig::make();

            BotPromptRevision::query()->create([
                'bot_setting_id' => $setting->id,
                'revision' => (int) $setting->prompt_revision,
                'prompt_config' => $setting->prompt_config,
                'reason' => 'Snapshot before YFS prompt reset',
            ]);

            $setting->forceFill([
                'prompt_config' => $next,
                'prompt_revision' => (int) $setting->prompt_revision + 1,
                'structured_prompts_ready' => true,
            ])->save();
        });

        $this->components->info('YFS prompt topics replaced.');

        return self::SUCCESS;
    }
}
