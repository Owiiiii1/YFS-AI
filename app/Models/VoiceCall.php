<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class VoiceCall extends Model
{
    protected $fillable = [
        'voice_contact_id',
        'elevenlabs_conversation_id',
        'twilio_call_sid',
        'phone',
        'language',
        'started_at',
        'ended_at',
        'duration_seconds',
        'status',
        'transcript',
        'summary',
        'recording_url',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'duration_seconds' => 'integer',
            'transcript' => 'array',
            'metadata' => 'array',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(VoiceContact::class, 'voice_contact_id');
    }

    public function followups(): HasMany
    {
        return $this->hasMany(VoiceFollowup::class);
    }

    public function analysis(): HasOne
    {
        return $this->hasOne(VoiceCallAnalysis::class);
    }
}
