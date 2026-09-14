<?php

namespace App\Console\Commands;

use App\Models\BotPromptRevision;
use App\Models\BotSetting;
use App\Support\BotPromptSchema;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RollbackStructuredBotPrompts extends Command
{
    protected $signature = 'bot:prompts:rollback {revision : Archived revision to restore}';

    protected $description = 'Atomically restore an archived bot prompt revision';

    public function handle(): int
    {
        $target = (int) $this->argument('revision');

        $restored = DB::transaction(function () use ($target): ?int {
            $setting = BotSetting::query()->orderBy('id')->lockForUpdate()->firstOrFail();
            $archive = BotPromptRevision::query()
                ->where('bot_setting_id', $setting->id)
                ->where('revision', $target)
                ->first();

            if ($archive === null) {
                return null;
            }

            $config = $archive->prompt_config;
            BotPromptSchema::assertValid($config);

            BotPromptRevision::query()->create([
                'bot_setting_id' => $setting->id,
                'revision' => (int) $setting->prompt_revision,
                'prompt_config' => $setting->prompt_config,
                'reason' => "Automatic snapshot before rollback to revision {$target}",
            ]);

            $nextRevision = (int) $setting->prompt_revision + 1;
            $setting->forceFill([
                'prompt_config' => $config,
                'prompt_revision' => $nextRevision,
                'structured_prompts_ready' => true,
            ])->save();

            return $nextRevision;
        });

        if ($restored === null) {
            $this->components->error("Archived revision {$target} was not found.");

            return self::FAILURE;
        }

        $this->components->info("Revision {$target} restored as active revision {$restored}.");

        return self::SUCCESS;
    }
}
