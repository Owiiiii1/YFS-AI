<?php

namespace App\Services\Messaging;

use App\Models\Conversation;
use App\Services\Facebook\ProcessIncomingFacebookMessageService;
use App\Services\Instagram\ProcessIncomingInstagramMessageService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

class RetryMissedBotRepliesService
{
    public function __construct(
        private readonly ProcessIncomingInstagramMessageService $instagramProcessor,
        private readonly ProcessIncomingFacebookMessageService $facebookProcessor,
    ) {}

    public function process(?int $conversationId = null, bool $force = false, bool $nudge = false): int
    {
        $replied = 0;
        $now = Carbon::now();

        $query = Conversation::query()
            ->where('bot_enabled', true)
            ->whereIn('status', [
                Conversation::STATUS_OPEN,
                Conversation::STATUS_AWAITING_PAYMENT,
            ])
            ->whereIn('channel', ['instagram', 'facebook']);

        if ($conversationId !== null) {
            $query->where('id', $conversationId);
        } else {
            $query
                ->where('last_message_at', '>=', $now->copy()->subMinutes(MissedBotReplyRetryTracker::MAX_AGE_MINUTES))
                ->where('last_message_at', '<=', $now->copy()->subSeconds(MissedBotReplyRetryTracker::MIN_AGE_SECONDS))
                ->orderBy('last_message_at')
                ->limit(15);
        }

        foreach ($query->get() as $conversation) {
            try {
                $sent = $conversation->channel === 'facebook'
                    ? $this->facebookProcessor->retryLatestUnansweredInbound($conversation, $force)
                    : $this->instagramProcessor->retryLatestUnansweredInbound($conversation, $force, $nudge);

                if ($sent) {
                    $replied++;
                }
            } catch (Throwable $exception) {
                Log::warning('Missed bot reply retry crashed.', [
                    'conversation_id' => $conversation->id,
                    'channel' => $conversation->channel,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        return $replied;
    }
}
