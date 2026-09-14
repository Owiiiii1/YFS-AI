<?php

namespace App\Services\Bot;

use App\Models\BotReply;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Services\Instagram\BotFollowUpService;
use App\Services\Instagram\HumanHandoffClassifier;
use Illuminate\Support\Carbon;

class OperatorHandoffRecoveryService
{
    public function __construct(
        private readonly HumanHandoffClassifier $handoffClassifier,
        private readonly BotOutcomeService $outcomes,
        private readonly BotFollowUpService $followUpService,
    ) {}

    public function recoverDue(?Carbon $since = null): int
    {
        $since ??= now()->subDays(7);
        $recovered = 0;

        Conversation::query()
            ->whereIn('channel', ['instagram', 'facebook'])
            ->where('last_message_at', '>=', $since)
            ->orderBy('id')
            ->each(function (Conversation $conversation) use (&$recovered): void {
                if ($this->recoverConversation($conversation)) {
                    $recovered++;
                }
            });

        return $recovered;
    }

    public function recoverConversation(Conversation $conversation): bool
    {
        if (! $this->needsRecovery($conversation)) {
            return false;
        }

        $conversation->forceFill([
            'status' => Conversation::STATUS_PENDING_HUMAN,
            'bot_enabled' => false,
        ])->save();
        $this->followUpService->clearAwaitingReply($conversation->fresh());

        $alreadyRecorded = BotReply::query()
            ->where('conversation_id', $conversation->id)
            ->where('type', BotReply::TYPE_OPERATOR_NEEDED)
            ->latest('id')
            ->first();

        if ($alreadyRecorded === null) {
            $this->outcomes->record(
                $conversation->fresh(),
                BotReply::TYPE_OPERATOR_NEEDED,
                'User needs a live operator. / Пользователю требуется оператор.',
                ['question' => $this->latestCustomerText($conversation)],
            );
        } elseif (! $alreadyRecorded->telegram_sent) {
            $this->outcomes->record(
                $conversation->fresh(),
                BotReply::TYPE_OPERATOR_NEEDED,
                'User needs a live operator. / Пользователю требуется оператор.',
                ['question' => $this->latestCustomerText($conversation)],
            );
        }

        return true;
    }

    public function needsRecovery(Conversation $conversation): bool
    {
        $claimAt = $this->latestHandoffClaimAt($conversation);
        if ($claimAt === null) {
            return false;
        }

        $outcome = BotReply::query()
            ->where('conversation_id', $conversation->id)
            ->where('type', BotReply::TYPE_OPERATOR_NEEDED)
            ->orderByDesc('id')
            ->first();

        if ($outcome === null || ! $outcome->telegram_sent) {
            return true;
        }

        return $claimAt->gt($outcome->created_at);
    }

    private function latestHandoffClaimAt(Conversation $conversation): ?Carbon
    {
        foreach ($conversation->messages()->orderByDesc('id')->limit(40)->get() as $message) {
            if ($message->direction !== ConversationMessage::DIRECTION_OUTBOUND) {
                continue;
            }

            if ($this->handoffClassifier->isHandoffClaimText((string) $message->body)) {
                return $message->sent_at ?? $message->created_at;
            }
        }

        return null;
    }

    private function latestCustomerText(Conversation $conversation): string
    {
        $inbound = $conversation->messages()
            ->where('direction', ConversationMessage::DIRECTION_INBOUND)
            ->orderByDesc('id')
            ->first();

        return trim((string) ($inbound?->body ?? ''));
    }
}
