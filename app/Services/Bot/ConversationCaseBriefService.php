<?php

namespace App\Services\Bot;

use App\Models\BotReply;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Services\Ai\AiReplyGenerator;
use Throwable;

class ConversationCaseBriefService
{
    public function __construct(
        private readonly AiReplyGenerator $ai,
    ) {}

    public function summarize(Conversation $conversation, string $type, string $latestMessage = ''): string
    {
        $transcript = $this->transcript($conversation);
        $latest = trim($latestMessage);

        try {
            $text = $this->ai->generate(
                $this->systemPrompt(),
                $this->userPrompt($type, $transcript, $latest),
                trace: [
                    'conversation_id' => $conversation->id,
                    'purpose' => 'case_brief',
                ],
            );

            return $this->clean($text) ?: $this->fallback($type, $transcript, $latest);
        } catch (Throwable) {
            return $this->fallback($type, $transcript, $latest);
        }
    }

    public function fallback(string $type, string $transcript, string $latestMessage = ''): string
    {
        $text = mb_strtolower($transcript.' '.$latestMessage);

        $about = match (true) {
            str_contains($text, 'low income') || str_contains($text, '1-2000') || str_contains($text, '750')
                => 'бюджет и стоимость участия',
            str_contains($text, 'коллаборац') || str_contains($text, 'brand') && str_contains($text, 'participat')
                => 'сотрудничество бренда и условия участия',
            str_contains($text, 'сколько') || str_contains($text, 'стоим') || str_contains($text, 'платн') || str_contains($text, 'price') || str_contains($text, 'how much')
                => 'стоимость участия',
            str_contains($text, 'бренд') || str_contains($text, 'designer')
                => 'бренды на шоу',
            str_contains($text, 'партнер') || str_contains($text, 'партнёр') || str_contains($text, 'owl') || str_contains($text, 'сотруднич')
                => 'партнёрство / коммерческое предложение',
            str_contains($text, '5 лет') || str_contains($text, 'дочь') || str_contains($text, 'девочк') || str_contains($text, 'ребён') || str_contains($text, 'ребен')
                => 'участие ребёнка',
            str_contains($text, 'как попасть') || str_contains($text, 'для участия')
                => 'как принять участие',
            default => 'участие в Young Fashion Show',
        };

        return match ($type) {
            BotReply::TYPE_OPERATOR_NEEDED => 'Клиент по теме «'.$about.'» попросил живого оператора.',
            BotReply::TYPE_FORM_SENT => 'Клиент по теме «'.$about.'» получил ссылку на анкету.',
            BotReply::TYPE_MANAGER_REQUEST => 'Обращение по теме «'.$about.'» передано менеджеру.',
            BotReply::TYPE_CLIENT_FOUND => 'Клиент найден в базе, тема: '.$about.'.',
            BotReply::TYPE_CLIENT_NOT_FOUND => 'Клиент не найден в базе, тема: '.$about.'.',
            default => 'Обращение по теме «'.$about.'».',
        };
    }

    private function systemPrompt(): string
    {
        return <<<'TXT'
You write a one-sentence briefing in Russian for a live YFS operator.
Say what the person wants and the situation in the dialog. One sentence only. No markdown, no quotes, no greeting.
Do not copy the last message verbatim if it is just "да/так/yes".
TXT;
    }

    private function userPrompt(string $type, string $transcript, string $latest): string
    {
        $typeLabel = match ($type) {
            BotReply::TYPE_OPERATOR_NEEDED => 'нужен оператор',
            BotReply::TYPE_FORM_SENT => 'отправлена анкета',
            BotReply::TYPE_MANAGER_REQUEST => 'запрос менеджеру',
            default => $type,
        };

        return "Тип обращения: {$typeLabel}\nПоследнее сообщение клиента: {$latest}\n\nДиалог:\n{$transcript}";
    }

    private function transcript(Conversation $conversation): string
    {
        $messages = $conversation->messages()
            ->orderByDesc('id')
            ->limit(24)
            ->get()
            ->reverse();

        $lines = [];
        foreach ($messages as $message) {
            $body = trim((string) $message->body);
            if ($body === '' || str_starts_with($body, '[')) {
                continue;
            }
            $who = match ($message->sender_type) {
                ConversationMessage::SENDER_CUSTOMER => 'Клиент',
                ConversationMessage::SENDER_BOT => 'Бот',
                default => 'Оператор',
            };
            $lines[] = $who.': '.preg_replace('/\s+/u', ' ', $body);
        }

        return implode("\n", $lines);
    }

    private function clean(string $text): string
    {
        $text = trim($text);
        $text = trim($text, " \t\n\r\0\x0B\"'«»");
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        if (mb_strlen($text) > 280) {
            $text = rtrim(mb_substr($text, 0, 277)).'…';
        }

        return $text;
    }
}
