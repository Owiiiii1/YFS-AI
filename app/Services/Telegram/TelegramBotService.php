<?php

namespace App\Services\Telegram;

use App\Models\TelegramSetting;
use App\Support\TelegramChatTarget;
use Illuminate\Support\Str;
use RuntimeException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\RunningMode\Webhook;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use Throwable;

class TelegramBotService
{
    public function webhookUrl(): string
    {
        return url('/api/telegram/webhook');
    }

    public function connectBot(TelegramSetting $settings, string $token): TelegramSetting
    {
        $token = trim($token);
        $bot = $this->makeBot($token);
        $me = $bot->getMe();
        if ($me === null || ! $me->is_bot) {
            throw new RuntimeException('Telegram did not return a valid bot profile.');
        }

        $secret = Str::lower(bin2hex(random_bytes(16)));
        $bot->setWebhook(
            $this->webhookUrl(),
            secret_token: $secret,
            drop_pending_updates: true,
        );

        $settings->forceFill([
            'bot_token_encrypted' => $token,
            'bot_id' => $me->id,
            'bot_username' => $me->username,
            'bot_name' => $me->first_name,
            'bot_status' => TelegramSetting::STATUS_CONNECTED,
            'webhook_secret' => $secret,
            'webhook_set_at' => now(),
            'last_error' => null,
        ])->save();

        return $settings->fresh() ?? $settings;
    }

    public function disconnectBot(TelegramSetting $settings): TelegramSetting
    {
        if (filled($settings->bot_token_encrypted)) {
            try {
                $this->makeBot((string) $settings->bot_token_encrypted)->deleteWebhook(true);
            } catch (Throwable) {
                // Local disconnect still proceeds if Telegram is unreachable.
            }
        }

        $settings->forceFill([
            'bot_token_encrypted' => null,
            'bot_id' => null,
            'bot_username' => null,
            'bot_name' => null,
            'bot_status' => TelegramSetting::STATUS_NOT_CONFIGURED,
            'webhook_secret' => null,
            'webhook_set_at' => null,
            'channel_id' => null,
            'channel_thread_id' => null,
            'channel_username' => null,
            'channel_title' => null,
            'channel_status' => TelegramSetting::STATUS_NOT_CONFIGURED,
            'last_error' => null,
        ])->save();

        return $settings->fresh() ?? $settings;
    }

    public function connectChannel(TelegramSetting $settings, string $channel, ?string $thread = null): TelegramSetting
    {
        if (! $settings->isBotConnected()) {
            throw new RuntimeException('Connect the Telegram bot first.');
        }

        $target = TelegramChatTarget::parse($channel, $thread);
        $bot = $this->makeBot((string) $settings->bot_token_encrypted);
        try {
            $chat = $bot->getChat($target->chat);
        } catch (TelegramException $exception) {
            throw new RuntimeException($exception->getMessage());
        }

        if ($chat === null) {
            throw new RuntimeException('Telegram did not return the chat.');
        }

        $type = $chat->type instanceof ChatType ? $chat->type : ChatType::tryFrom((string) $chat->type);
        if (! in_array($type, [ChatType::CHANNEL, ChatType::SUPERGROUP, ChatType::GROUP], true)) {
            throw new RuntimeException('Bind a Telegram channel or group, not a private chat.');
        }

        $isForum = (bool) $chat->is_forum;
        if ($target->threadId !== null && ! $isForum) {
            throw new RuntimeException('Topics exist only in a Telegram group with threads. This chat has no topics.');
        }

        if ($target->threadId !== null) {
            try {
                $bot->sendMessage(
                    text: "Young Fashion Show bot is connected to this topic.\nБот Young Fashion Show подключён к этой ветке.",
                    chat_id: $chat->id,
                    message_thread_id: $target->threadId,
                );
            } catch (TelegramException $exception) {
                throw new RuntimeException($exception->getMessage());
            }
        }

        $title = trim((string) ($chat->title ?: $chat->username));
        $kind = $type === ChatType::CHANNEL ? 'канал' : 'группа';
        if ($title !== '') {
            $title .= ' ('.$kind.')';
        } else {
            $title = $kind;
        }
        if ($target->threadId !== null) {
            $title .= ' · ветка '.$target->threadId;
        }

        $settings->forceFill([
            'channel_id' => (string) $chat->id,
            'channel_thread_id' => $target->threadId,
            'channel_username' => $chat->username ? '@'.$chat->username : null,
            'channel_title' => $title,
            'channel_status' => TelegramSetting::STATUS_CONNECTED,
            'last_error' => null,
        ])->save();

        return $settings->fresh() ?? $settings;
    }

    public function disconnectChannel(TelegramSetting $settings): TelegramSetting
    {
        $settings->forceFill([
            'channel_id' => null,
            'channel_thread_id' => null,
            'channel_username' => null,
            'channel_title' => null,
            'channel_status' => TelegramSetting::STATUS_NOT_CONFIGURED,
        ])->save();

        return $settings->fresh() ?? $settings;
    }

    public function processWebhook(TelegramSetting $settings, ?string $secretHeader): bool
    {
        if (! $settings->isBotConnected()) {
            return true;
        }

        $expected = (string) $settings->webhook_secret;
        if ($expected !== '' && ! hash_equals($expected, (string) $secretHeader)) {
            return false;
        }

        $bot = $this->makeBot((string) $settings->bot_token_encrypted);
        $this->registerHandlers($bot);
        $mode = new Webhook(static fn (): string => (string) $secretHeader, $expected !== '' ? $expected : null);
        if ($expected !== '') {
            $mode->setSafeMode(true);
        }
        $bot->setRunningMode($mode);
        $bot->run();

        return true;
    }

    public function registerHandlers(Nutgram $bot): void
    {
        $bot->onCommand('start', function (Nutgram $bot): void {
            $bot->sendMessage(
                "Hello! This is the YoungFashionShow bot.\nПривет! Это бот YoungFashionShow."
            );
        });
    }

    public function sendChannelText(string $text): void
    {
        $settings = TelegramSetting::current();
        if (! $settings->isChannelConnected() || ! filled($settings->bot_token_encrypted) || ! filled($settings->channel_id)) {
            throw new RuntimeException('Telegram channel or group is not connected.');
        }

        $threadId = $settings->channel_thread_id !== null ? (int) $settings->channel_thread_id : null;
        $bot = $this->makeBot((string) $settings->bot_token_encrypted);
        $bot->sendMessage(
            text: $text,
            chat_id: $settings->channel_id,
            message_thread_id: $threadId,
        );
    }

    public function makeBot(string $token): Nutgram
    {
        return new Nutgram($token);
    }
}
