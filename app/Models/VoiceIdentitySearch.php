<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VoiceIdentitySearch extends Model
{
    public const PENDING = 'pending';

    public const RUNNING = 'running';

    public const UNIQUE = 'unique';

    public const AMBIGUOUS = 'ambiguous';

    public const NOT_FOUND = 'not_found';

    public const FAILED = 'failed';

    protected $fillable = [
        'voice_contact_id',
        'elevenlabs_conversation_id',
        'status',
        'hints',
        'result_metadata',
        'started_at',
        'completed_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'hints' => 'array',
            'result_metadata' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(VoiceContact::class, 'voice_contact_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::PENDING, self::RUNNING], true) && ! $this->isExpired();
    }
}
