<?php

namespace App\Services\Facebook;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\FacebookPageAccount;
use Illuminate\Support\Carbon;

class ProcessOutgoingFacebookEchoService
{
    public function __construct(
        private readonly \App\Services\Instagram\BotConversationContextBuilder $contextBuilder,
        private readonly ProcessIncomingFacebookMessageService $incomingService,
    ) {}

    /**
     * @param  array{recipient_id: string, message_id: string, text: string, timestamp?: int|string|null}  $payload
     */
    public function handle(array $payload): void
    {
        $account = FacebookPageAccount::primary();
        if (! $account->isConnected()) {
            return;
        }

        $recipientId = trim((string) ($payload['recipient_id'] ?? ''));
        $messageId = trim((string) ($payload['message_id'] ?? ''));
        $text = trim((string) ($payload['text'] ?? ''));

        if ($recipientId === '' || $messageId === '') {
            return;
        }

        if (ConversationMessage::query()->where('external_id', $messageId)->exists()) {
            return;
        }

        $sentAt = $this->resolveTimestamp($payload['timestamp'] ?? null);

        $conversation = Conversation::query()->firstOrCreate(
            [
                'channel' => 'facebook',
                'participant_id' => $recipientId,
            ],
            [
                'status' => Conversation::STATUS_OPEN,
                'bot_enabled' => true,
                'last_message_at' => $sentAt,
            ],
        );

        $this->incomingService->hydrateParticipantProfile($conversation->fresh(), $account, $recipientId);
        $this->contextBuilder->linkCustomer($conversation->fresh());

        ConversationMessage::query()->create([
            'conversation_id' => $conversation->id,
            'external_id' => $messageId,
            'direction' => ConversationMessage::DIRECTION_OUTBOUND,
            'sender_type' => ConversationMessage::SENDER_OPERATOR,
            'sent_via' => ConversationMessage::SENT_VIA_FACEBOOK,
            'body' => $text !== '' ? $text : '[attachment or unsupported message]',
            'sent_at' => $sentAt,
            'read_at' => $sentAt,
        ]);

        $conversation->refresh();

        $conversation->forceFill([
            'last_message_at' => ($conversation->last_message_at === null || $sentAt->gte($conversation->last_message_at))
                ? $sentAt
                : $conversation->last_message_at,
            'status' => $conversation->status === Conversation::STATUS_PENDING_HUMAN
                ? Conversation::STATUS_OPEN
                : $conversation->status,
            'bot_awaits_reply' => false,
            'bot_awaiting_topic' => null,
            'bot_awaiting_since' => null,
            'bot_reminder_sent_at' => null,
        ])->save();
    }

    private function resolveTimestamp(mixed $timestamp): Carbon
    {
        if (is_numeric($timestamp)) {
            $value = (int) $timestamp;

            return $value > 9999999999
                ? Carbon::createFromTimestampMs($value)
                : Carbon::createFromTimestamp($value);
        }

        return Carbon::now();
    }
}
