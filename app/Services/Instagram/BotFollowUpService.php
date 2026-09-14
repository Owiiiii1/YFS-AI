<?php

namespace App\Services\Instagram;

use App\Models\Conversation;
use Illuminate\Support\Carbon;

class BotFollowUpService
{
    public function clearAwaitingReply(Conversation $conversation): void
    {
        if (! $conversation->bot_awaits_reply
            && $conversation->bot_awaiting_topic === null
            && $conversation->bot_awaiting_since === null
            && $conversation->bot_reminder_sent_at === null) {
            return;
        }

        $conversation->forceFill([
            'bot_awaits_reply' => false,
            'bot_awaiting_topic' => null,
            'bot_awaiting_since' => null,
            'bot_reminder_sent_at' => null,
        ])->save();
    }

    public function markAwaitingReply(Conversation $conversation, string $replyText, ?string $topic = null): void
    {
        $isExplicitHandoff = $topic === HumanHandoffClassifier::TOPIC_OPERATOR_OFFER;
        if (! $isExplicitHandoff && ! $this->shouldTrackAwaitingReply($conversation, $replyText)) {
            $this->clearAwaitingReply($conversation);

            return;
        }
        $resolvedTopic = $topic ?: $this->resolveAskedTopic($replyText);

        $conversation->forceFill([
            'bot_awaits_reply' => true,
            'bot_awaiting_topic' => $resolvedTopic,
            'bot_awaiting_since' => Carbon::now(),
            'bot_reminder_sent_at' => null,
        ])->save();
    }

    public function shouldTrackAwaitingReply(Conversation $conversation, string $replyText): bool
    {
        if (! $conversation->bot_enabled) {
            return false;
        }

        if ($conversation->status !== Conversation::STATUS_OPEN) {
            return false;
        }

        if ($this->looksLikeClosingMessage($replyText)) {
            return false;
        }

        return str_contains($replyText, '?')
            || $this->looksLikeAwaitingChoice($replyText);
    }

    public function resolveAskedTopic(string $replyText): string
    {
        $text = mb_strtolower($replyText);
        $topics = [
            'events' => ['event', 'ивент', 'івент', 'chicago', 'new york', 'дата', 'date'],
            'form' => ['form', 'application', 'заявк', 'анкет'],
            'operator' => ['operator', 'manager', 'оператор', 'менеджер'],
        ];

        foreach ($topics as $topic => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($text, $needle)) {
                    return $topic;
                }
            }
        }

        return 'reply';
    }

    public function processDueReminders(): int
    {
        return 0;
    }

    private function looksLikeClosingMessage(string $replyText): bool
    {
        $text = mb_strtolower($replyText);

        foreach ([
            'application is registered',
            'request has been accepted',
            'заявку зареєстровано',
            'заявку прийнято',
            'заявка зарегистрирована',
            'заявка принята',
            'an operator will contact',
            'звʼяжеться оператор',
            'свяжется оператор',
            'requires a manager',
            'потребує участі менеджера',
            'требует участия менеджера',
            'ожидайте его ответа',
            'очікуйте на його відповідь',
            'please wait for their reply',
            'please wait for a message from our manager',
            'receipt received',
            'квитанц',
            'manager will',
            'менеджер',
        ] as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeAwaitingChoice(string $replyText): bool
    {
        $text = mb_strtolower($replyText);

        foreach ([
            'which', 'choose', 'prefer', 'confirm', 'let me know', 'tell me',
            'какой', 'какую', 'выберите', 'подтверд', 'подскажите',
            'який', 'яку', 'оберіть', 'підтверд', 'підкажіть',
        ] as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }
}
