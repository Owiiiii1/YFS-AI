<?php

namespace App\Support;

use RuntimeException;

final class TelegramChatTarget
{
    public function __construct(
        public readonly string $chat,
        public readonly ?int $threadId,
    ) {}

    public static function parse(string $channel, ?string $thread = null): self
    {
        $channel = trim($channel);
        $thread = trim((string) $thread);

        if ($channel === '') {
            throw new RuntimeException('Enter a channel or group @username, ID, or a message link.');
        }

        $fromLink = self::parseLink($channel);
        $chat = $fromLink['chat'] ?? $channel;
        $threadId = $fromLink['thread_id'] ?? null;

        if ($thread !== '') {
            $override = self::parseThread($thread);
            if ($override === null) {
                throw new RuntimeException('Enter a numeric topic ID or a message link from that topic.');
            }
            $threadId = $override;
        }

        return new self($chat, $threadId);
    }

    private static function parseThread(string $value): ?int
    {
        if (preg_match('/^\d+$/', $value) === 1) {
            $id = (int) $value;

            return $id > 0 ? $id : null;
        }

        return self::parseLink($value)['thread_id'] ?? null;
    }

    /**
     * @return array{chat:string,thread_id:?int}|null
     */
    private static function parseLink(string $value): ?array
    {
        $value = trim($value);

        if (preg_match('~(?:https?://)?(?:t(?:elegram)?\.me|telegram\.dog)/c/(\d+)(?:/(\d+))?(?:/(\d+))?~i', $value, $match) === 1) {
            return [
                'chat' => '-100'.$match[1],
                'thread_id' => self::positiveInt($match[2] ?? null),
            ];
        }

        if (preg_match('~(?:https?://)?(?:t(?:elegram)?\.me|telegram\.dog)/([A-Za-z][A-Za-z0-9_]{3,})(?:/(\d+))?(?:/(\d+))?~i', $value, $match) === 1) {
            $username = strtolower($match[1]);
            if (in_array($username, ['addstickers', 'share', 'joinchat', 'proxy', 'socks', 'setlanguage'], true)) {
                return null;
            }

            return [
                'chat' => '@'.$match[1],
                'thread_id' => self::positiveInt($match[2] ?? null),
            ];
        }

        return null;
    }

    private static function positiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $id = (int) $value;

        return $id > 0 ? $id : null;
    }
}
