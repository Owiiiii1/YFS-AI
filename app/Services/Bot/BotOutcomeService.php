<?php

namespace App\Services\Bot;

use App\Models\BotReply;
use App\Models\Conversation;
use App\Services\Telegram\TelegramBotService;
use Illuminate\Support\Facades\Log;
use Throwable;

class BotOutcomeService
{
    public function __construct(
        private readonly TelegramBotService $telegram,
        private readonly ConversationCaseBriefService $briefs,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(
        Conversation $conversation,
        string $type,
        string $summary,
        array $payload = [],
        bool $notifyTelegram = true,
    ): BotReply {
        $brief = $this->briefs->summarize(
            $conversation,
            $type,
            (string) ($payload['question'] ?? $summary),
        );
        $payload['brief'] = $brief;

        $reply = BotReply::query()->create([
            'conversation_id' => $conversation->id,
            'customer_id' => $conversation->customer_id,
            'channel' => $conversation->channel,
            'participant_username' => $conversation->displayUsername(),
            'type' => $type,
            'summary' => $brief,
            'payload' => $payload,
            'telegram_sent' => false,
        ]);

        if (! $notifyTelegram || ! $this->shouldNotifyTelegram($type)) {
            return $reply;
        }

        try {
            $this->telegram->sendChannelText($this->telegramText($reply, $conversation, $payload));
            $reply->forceFill(['telegram_sent' => true])->save();
        } catch (Throwable $exception) {
            Log::warning('Bot outcome Telegram send failed.', [
                'bot_reply_id' => $reply->id,
                'message' => $exception->getMessage(),
            ]);
        }

        return $reply;
    }

    private function shouldNotifyTelegram(string $type): bool
    {
        return $type !== BotReply::TYPE_FORM_SENT;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function telegramText(BotReply $reply, Conversation $conversation, array $payload): string
    {
        $username = $reply->participant_username ? '@'.ltrim($reply->participant_username, '@') : 'unknown';
        $typeLabel = match ($reply->type) {
            BotReply::TYPE_FORM_SENT => 'Отправлена анкета',
            BotReply::TYPE_OPERATOR_NEEDED => 'Нужен оператор',
            BotReply::TYPE_MANAGER_REQUEST => 'Запрос менеджеру',
            BotReply::TYPE_CLIENT_FOUND => 'Клиент найден',
            BotReply::TYPE_CLIENT_NOT_FOUND => 'Клиент не найден',
            default => $reply->type,
        };

        $lines = [
            $typeLabel,
            $username.' · диалог #'.$conversation->id,
            '',
            'Суть: '.$reply->summary,
        ];

        $email = $payload['email'] ?? null;
        if (filled($email)) {
            $lines[] = 'Email: '.$email;
        }

        $client = $payload['client'] ?? null;
        if (is_array($client)) {
            $lines[] = 'JFS client #'.($client['id'] ?? '?').' '.$this->joinName($client);
            $lines[] = 'Phone: '.($client['phone'] ?? '—');
            $lines[] = 'Role: '.($client['role'] ?? '—');
        }

        $children = $payload['children'] ?? [];
        if (is_array($children) && $children !== []) {
            $lines[] = 'Children:';
            foreach ($children as $child) {
                if (! is_array($child)) {
                    continue;
                }
                $lines[] = '- '.trim(($child['first_name'] ?? '').' '.($child['last_name'] ?? ''))
                    .(filled($child['birthdate'] ?? null) ? ' ('.$child['birthdate'].')' : '');
            }
        }

        $question = trim((string) ($payload['question'] ?? ''));
        if ($question !== '' && mb_strtolower($question) !== mb_strtolower((string) $reply->summary)) {
            $lines[] = '';
            $lines[] = 'Последнее сообщение: '.$question;
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $client
     */
    private function joinName(array $client): string
    {
        return trim((string) ($client['name'] ?? ''));
    }
}
