<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'channel',
    'customer_id',
    'participant_id',
    'participant_name',
    'participant_username',
    'status',
    'bot_enabled',
    'bot_manual_mode_at',
    'bot_awaits_reply',
    'bot_awaiting_topic',
    'bot_awaiting_since',
    'bot_reminder_sent_at',
    'intake_status',
    'intake_data',
    'intake_order_id',
    'last_message_at',
])]
class Conversation extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_PENDING_HUMAN = 'pending_human';

    public const STATUS_AWAITING_PAYMENT = 'awaiting_payment';

    public const STATUS_ORDER_IN_PROGRESS = 'order_in_progress';

    public const STATUS_CLOSED = 'closed';

    public function messages(): HasMany
    {
        return $this->hasMany(ConversationMessage::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function displayName(): string
    {
        if (filled($this->customer?->name)) {
            return (string) $this->customer->name;
        }

        if (filled($this->participant_name)) {
            return (string) $this->participant_name;
        }

        if (filled($this->participant_username)) {
            return '@'.ltrim((string) $this->participant_username, '@');
        }

        return (string) $this->participant_id;
    }

    public function displayUsername(): ?string
    {
        $username = ltrim((string) $this->participant_username, '@');

        if ($username !== '' && ! $this->participantUsernameMatchesAccount($username)) {
            return $username;
        }

        $customerUsername = ltrim((string) $this->customer?->instagram_username, '@');

        return $customerUsername !== '' ? $customerUsername : null;
    }

    public function participantUsernameMatchesAccount(?string $username = null): bool
    {
        $username = strtolower(ltrim((string) ($username ?? $this->participant_username), '@'));

        if ($username === '') {
            return false;
        }

        $account = InstagramAccount::primary();
        $accountUsername = strtolower(ltrim((string) data_get($account->settings, 'instagram_username', $account->name), '@'));

        if ($accountUsername !== '' && $username === $accountUsername) {
            return true;
        }

        return $account->ownsInstagramId((string) $this->participant_id);
    }

    public function requiresOperator(): bool
    {
        return $this->status === self::STATUS_PENDING_HUMAN;
    }

    public function isAwaitingPayment(): bool
    {
        return $this->status === self::STATUS_AWAITING_PAYMENT;
    }

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'bot_enabled' => 'boolean',
            'bot_manual_mode_at' => 'datetime',
            'bot_awaits_reply' => 'boolean',
            'bot_awaiting_since' => 'datetime',
            'bot_reminder_sent_at' => 'datetime',
            'intake_data' => 'array',
        ];
    }
}
