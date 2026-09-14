<?php

namespace App\Services\Bot;

use App\Models\BotPromptRevision;
use App\Models\BotSetting;
use App\Support\BotPromptSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BotPromptApplyService
{
    public function __construct(
        private readonly BotPromptPatchService $patches,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public function apply(
        array $config,
        int $expectedRevision,
        ?int $changedBy,
        string $reason,
        ?bool $botEnabled = null,
    ): BotSetting {
        BotPromptSchema::assertValid($config);
        $this->patches->assertPlaceholdersValid($config);

        return DB::transaction(function () use (
            $config,
            $expectedRevision,
            $changedBy,
            $reason,
            $botEnabled,
        ): BotSetting {
            $setting = BotSetting::query()->orderBy('id')->lockForUpdate()->firstOrFail();
            if ((int) $setting->prompt_revision !== $expectedRevision) {
                throw ValidationException::withMessages([
                    'prompt_revision' => __('client.bot.prompt_changed_elsewhere'),
                ]);
            }

            BotPromptRevision::query()->create([
                'bot_setting_id' => $setting->id,
                'revision' => (int) $setting->prompt_revision,
                'prompt_config' => $setting->prompt_config,
                'changed_by' => $changedBy,
                'reason' => $reason,
            ]);

            $values = [
                'prompt_config' => $config,
                'prompt_revision' => (int) $setting->prompt_revision + 1,
                'structured_prompts_ready' => true,
            ];
            if ($botEnabled !== null) {
                $values['bot_enabled'] = $botEnabled;
            }
            $setting->forceFill($values)->save();

            return $setting;
        });
    }
}
