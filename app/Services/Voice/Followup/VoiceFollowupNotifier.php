<?php

namespace App\Services\Voice\Followup;

use App\Models\VoiceFollowup;
use App\Services\Telegram\TelegramBotService;
use Illuminate\Support\Facades\Log;
use Throwable;

final class VoiceFollowupNotifier
{
    public function __construct(
        private readonly TelegramBotService $telegram,
        private readonly VoiceFollowupTelegramFormatter $formatter,
    ) {}

    /**
     * Send once. If telegram_sent_at is already set, this is a no-op.
     * Failures leave telegram_sent_at null so a later retry can send the first message.
     */
    public function sendIfNeeded(VoiceFollowup $followup, bool $recovered = false): bool
    {
        if ($followup->telegramDelivered()) {
            return true;
        }

        $text = $this->formatter->format($followup, $recovered);

        try {
            $this->telegram->sendChannelText($text);
        } catch (Throwable $exception) {
            Log::warning('voice.followup.telegram_failed', [
                'followup_id' => $followup->id,
                'recovered' => $recovered,
                'message' => $exception->getMessage(),
            ]);

            return false;
        }

        $followup->forceFill(['telegram_sent_at' => now()])->save();

        return true;
    }
}
