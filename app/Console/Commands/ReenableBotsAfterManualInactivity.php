<?php

namespace App\Console\Commands;

use App\Models\BotSetting;
use App\Models\Conversation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ReenableBotsAfterManualInactivity extends Command
{
    protected $signature = 'bot:reenable-after-manual-inactivity';

    protected $description = 'Re-enable conversation bots after configured manual-mode inactivity';

    public function handle(): int
    {
        $settings = BotSetting::instance();
        if (! $settings->bot_enabled) {
            $this->components->info('Global bot is disabled; no conversations changed.');

            return self::SUCCESS;
        }

        $days = max(1, (int) $settings->manual_mode_reenable_days);
        $inactiveBefore = now()->subDays($days);
        $reenabled = 0;

        Conversation::query()
            ->whereIn('channel', ['instagram', 'facebook'])
            ->where('bot_enabled', false)
            ->whereNotNull('bot_manual_mode_at')
            ->where('bot_manual_mode_at', '<=', $inactiveBefore)
            ->whereIn('status', [Conversation::STATUS_OPEN, Conversation::STATUS_PENDING_HUMAN])
            ->where(function ($query) use ($inactiveBefore): void {
                $query->whereNull('last_message_at')
                    ->orWhere('last_message_at', '<=', $inactiveBefore);
            })
            ->orderBy('id')
            ->chunkById(100, function ($conversations) use (&$reenabled, $days): void {
                foreach ($conversations as $conversation) {
                    $conversation->forceFill([
                        'bot_enabled' => true,
                        'bot_manual_mode_at' => null,
                        'status' => $conversation->status === Conversation::STATUS_PENDING_HUMAN
                            ? Conversation::STATUS_OPEN
                            : $conversation->status,
                    ])->save();
                    $reenabled++;

                    Log::info('Conversation bot automatically re-enabled after manual inactivity.', [
                        'conversation_id' => $conversation->id,
                        'channel' => $conversation->channel,
                        'inactivity_days' => $days,
                    ]);
                }
            });

        $this->components->info("Conversation bots re-enabled: {$reenabled}");

        return self::SUCCESS;
    }
}
