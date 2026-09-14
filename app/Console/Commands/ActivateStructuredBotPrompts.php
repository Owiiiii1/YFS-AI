<?php

namespace App\Console\Commands;

use App\Models\BotSetting;
use App\Support\BotPromptSchema;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ActivateStructuredBotPrompts extends Command
{
    protected $signature = 'bot:prompts:activate {--force : Activate without an interactive confirmation}';

    protected $description = 'Atomically switch bot runtime to the validated structured prompt snapshot';

    public function handle(): int
    {
        $setting = BotSetting::instance();
        $config = is_array($setting->prompt_config) ? $setting->prompt_config : [];
        BotPromptSchema::assertValid($config);

        if (! $this->option('force') && ! $this->confirm(
            'Activate revision '.(int) $setting->prompt_revision.' for all subsequent bot turns?',
        )) {
            $this->components->warn('Activation cancelled.');

            return self::FAILURE;
        }

        DB::transaction(function (): void {
            $setting = BotSetting::query()->orderBy('id')->lockForUpdate()->firstOrFail();
            BotPromptSchema::assertValid((array) $setting->prompt_config);
            $setting->forceFill(['structured_prompts_ready' => true])->save();
        });

        $this->components->info('Structured prompt configuration is active.');

        return self::SUCCESS;
    }
}
