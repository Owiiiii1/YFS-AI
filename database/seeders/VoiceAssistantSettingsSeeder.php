<?php

namespace Database\Seeders;

use App\Models\VoiceAssistantSetting;
use Illuminate\Database\Seeder;

class VoiceAssistantSettingsSeeder extends Seeder
{
    public function run(): void
    {
        VoiceAssistantSetting::ensureDefaults();
    }
}
