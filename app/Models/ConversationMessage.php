<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

#[Fillable([
    'conversation_id',
    'external_id',
    'direction',
    'sender_type',
    'sent_via',
    'body',
    'attachment_path',
    'attachment_mime',
    'read_at',
    'sent_at',
])]
class ConversationMessage extends Model
{
    public const DIRECTION_INBOUND = 'inbound';

    public const DIRECTION_OUTBOUND = 'outbound';

    public const SENDER_CUSTOMER = 'customer';

    public const SENDER_OPERATOR = 'operator';

    public const SENDER_BOT = 'bot';

    public const SENT_VIA_CRM = 'crm';

    public const SENT_VIA_INSTAGRAM = 'instagram';

    public const SENT_VIA_FACEBOOK = 'facebook';

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function attachmentUrl(): ?string
    {
        if (! filled($this->attachment_path)) {
            return null;
        }

        return Storage::disk('public')->url($this->attachment_path);
    }

    public function isInbound(): bool
    {
        return $this->direction === self::DIRECTION_INBOUND;
    }

    public function aiContextLabel(): string
    {
        return match ($this->sender_type) {
            self::SENDER_CUSTOMER => 'Customer',
            self::SENDER_BOT => 'Bot (you)',
            self::SENDER_OPERATOR => match ($this->sent_via) {
                self::SENT_VIA_INSTAGRAM => 'Human operator (Instagram)',
                self::SENT_VIA_FACEBOOK => 'Human operator (Facebook)',
                default => 'Human operator (CRM)',
            },
            default => 'Unknown',
        };
    }

    public static function markInboundRead(?int $conversationId = null): int
    {
        $query = self::query()
            ->where('direction', self::DIRECTION_INBOUND)
            ->whereNull('read_at');

        if ($conversationId !== null) {
            $query->where('conversation_id', $conversationId);
        }

        return (int) $query->update(['read_at' => now()]);
    }

    public static function totalUnreadInboundCount(?string $channel = null): int
    {
        $query = self::query()
            ->where('direction', self::DIRECTION_INBOUND)
            ->whereNull('read_at');

        if ($channel !== null && $channel !== '') {
            $query->whereHas('conversation', static fn ($q) => $q->where('channel', $channel));
        }

        return (int) $query->count();
    }

    /**
     * @param  list<array{id?: string|null, body: string}>  $parts
     * @return list<int>
     */
    public static function saveBotOutbound(int $conversationId, array $parts, \DateTimeInterface $sentAt): array
    {
        $ids = [];

        foreach ($parts as $part) {
            $body = (string) ($part['body'] ?? '');
            $externalId = filled($part['id'] ?? null) ? (string) $part['id'] : null;
            $attributes = [
                'conversation_id' => $conversationId,
                'direction' => self::DIRECTION_OUTBOUND,
                'sender_type' => self::SENDER_BOT,
                'body' => $body,
                'sent_at' => $sentAt,
                'read_at' => $sentAt,
            ];

            if ($externalId !== null) {
                $saved = self::query()->updateOrCreate(
                    ['external_id' => $externalId],
                    $attributes,
                );
            } else {
                $saved = self::query()->create($attributes);
            }

            $ids[] = $saved->id;
        }

        return $ids;
    }

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }
}
