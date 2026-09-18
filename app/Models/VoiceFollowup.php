<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VoiceFollowup extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const DEPARTMENT_SALES = 'sales';

    public const DEPARTMENT_SUPPORT = 'support';

    public const SOURCE_VOICE = 'voice';

    public const CREATED_BY_LIVE_TOOL = 'live_tool';

    public const CREATED_BY_SAFETY_NET = 'post_call_safety_net';

    protected $fillable = [
        'voice_call_id',
        'voice_contact_id',
        'elevenlabs_conversation_id',
        'department',
        'reason',
        'status',
        'callback_requested',
        'callback_phone',
        'preferred_callback_time',
        'customer_name',
        'child_name',
        'show_city',
        'summary',
        'source',
        'telegram_sent_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'callback_requested' => 'boolean',
            'telegram_sent_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(VoiceContact::class, 'voice_contact_id');
    }

    public function call(): BelongsTo
    {
        return $this->belongsTo(VoiceCall::class, 'voice_call_id');
    }

    public function telegramDelivered(): bool
    {
        return $this->telegram_sent_at !== null;
    }

    public function createdBy(): string
    {
        $metadata = is_array($this->metadata) ? $this->metadata : [];

        return is_string($metadata['created_by'] ?? null) ? $metadata['created_by'] : self::CREATED_BY_LIVE_TOOL;
    }
}
