<?php

namespace App\Services\Messaging;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Services\Bot\BotDecisionTraceService;
use Illuminate\Support\Facades\Log;

trait ProcessesInboundMessageBatches
{
    /** Wait for follow-up messages in the same burst before answering. */
    private const BATCH_DEBOUNCE_MICROSECONDS = 8_000_000;

    /**
     * Pause briefly, then decide whether this inbound should produce the reply.
     * Returns false when a newer customer message arrived — that later job will answer the whole burst.
     */
    protected function shouldReplyAsBatchLeader(Conversation $conversation, ConversationMessage $inboundMessage): bool
    {
        usleep(self::BATCH_DEBOUNCE_MICROSECONDS);

        return ! $this->hasNewerInboundThan($conversation, $inboundMessage);
    }

    protected function hasNewerInboundThan(Conversation $conversation, ConversationMessage $inboundMessage): bool
    {
        return ConversationMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', ConversationMessage::DIRECTION_INBOUND)
            ->where('id', '>', $inboundMessage->id)
            ->exists();
    }

    protected function staleBecauseNewerInbound(
        Conversation $conversation,
        ConversationMessage $inboundMessage,
        string $channel,
    ): bool {
        if (! $this->hasNewerInboundThan($conversation, $inboundMessage)) {
            return false;
        }

        Log::info($channel.' auto-reply discarded: newer inbound arrived during generation.', [
            'conversation_id' => $conversation->id,
            'inbound_id' => $inboundMessage->id,
        ]);
        BotDecisionTraceService::finishIfRunning(
            'stale_discard',
            'newer_inbound_arrived_during_generation',
        );

        return true;
    }

    /**
     * Combine all customer messages since the last bot/operator reply into one turn.
     */
    protected function assemblePendingInboundBatch(Conversation $conversation): string
    {
        $lastOutboundId = ConversationMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', ConversationMessage::DIRECTION_OUTBOUND)
            ->orderByDesc('id')
            ->value('id');

        $query = ConversationMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', ConversationMessage::DIRECTION_INBOUND)
            ->orderBy('id');

        if ($lastOutboundId !== null) {
            $query->where('id', '>', $lastOutboundId);
        }

        $parts = [];

        foreach ($query->get(['body', 'attachment_path']) as $message) {
            $body = trim((string) ($message->body ?? ''));

            if ($body !== '') {
                $parts[] = $body;

                continue;
            }

            if (filled($message->attachment_path)) {
                $parts[] = 'Customer sent an inspiration image.';
            }
        }

        $parts = array_values(array_unique($parts));

        return trim(implode("\n", $parts));
    }
}
