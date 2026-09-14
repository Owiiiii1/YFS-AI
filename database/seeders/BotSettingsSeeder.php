<?php

namespace Database\Seeders;

use App\Models\BotSetting;
use App\Support\DefaultBotPromptConfig;
use Illuminate\Database\Seeder;

class BotSettingsSeeder extends Seeder
{
    public function run(): void
    {
        $setting = BotSetting::instance();

        $setting->forceFill([
            'bot_enabled' => true,
            'prompt_config' => DefaultBotPromptConfig::make(),
            'prompt_revision' => 1,
            'structured_prompts_ready' => true,
        ])->save();
    }
}
