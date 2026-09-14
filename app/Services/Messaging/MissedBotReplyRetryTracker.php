<?php

namespace App\Services\Messaging;

use App\Models\ConversationMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class MissedBotReplyRetryTracker
{
    public const MIN_AGE_SECONDS = 90;

    public const MAX_AGE_MINUTES = 720;

    public const MAX_ATTEMPTS = 5;

    /** @var list<int> */
    private const BACKOFF_SECONDS = [120, 300, 600, 900];

    public function canRetry(ConversationMessage $inboundMessage): bool
    {
        $sentAt = $this->sentAt($inboundMessage);
        $ageSeconds = $sentAt->diffInSeconds(Carbon::now());

        if ($ageSeconds < self::MIN_AGE_SECONDS) {
            return false;
        }

        if ($ageSeconds > self::MAX_AGE_MINUTES * 60) {
            return false;
        }

        $state = Cache::get($this->key($inboundMessage));
        if (! is_array($state)) {
            return true;
        }

        if ((int) ($state['attempts'] ?? 0) >= self::MAX_ATTEMPTS) {
            return false;
        }

        $nextAt = (int) ($state['next_at'] ?? 0);

        return $nextAt <= Carbon::now()->timestamp;
    }

    public function markFailed(ConversationMessage $inboundMessage): void
    {
        $state = Cache::get($this->key($inboundMessage));
        $attempts = is_array($state) ? ((int) ($state['attempts'] ?? 0) + 1) : 1;
        $delayIndex = min($attempts - 1, count(self::BACKOFF_SECONDS) - 1);
        $exhausted = $attempts >= self::MAX_ATTEMPTS;

        Cache::put($this->key($inboundMessage), [
            'attempts' => $attempts,
            'next_at' => $exhausted ? null : Carbon::now()->addSeconds(self::BACKOFF_SECONDS[$delayIndex])->timestamp,
        ], Carbon::now()->addHours(2));

        Log::warning($exhausted ? 'Bot auto-reply retries exhausted.' : 'Bot auto-reply scheduled for retry.', [
            'inbound_id' => $inboundMessage->id,
            'conversation_id' => $inboundMessage->conversation_id,
            'attempts' => $attempts,
        ]);
    }

    public function clear(ConversationMessage $inboundMessage): void
    {
        Cache::forget($this->key($inboundMessage));
    }

    public function sentAt(ConversationMessage $message): Carbon
    {
        if ($message->sent_at instanceof Carbon) {
            return $message->sent_at;
        }

        if (filled($message->sent_at)) {
            return Carbon::parse($message->sent_at);
        }

        if ($message->created_at instanceof Carbon) {
            return $message->created_at;
        }

        return Carbon::now();
    }

    private function key(ConversationMessage $inboundMessage): string
    {
        return 'bot-missed-reply:'.$inboundMessage->id;
    }
}
