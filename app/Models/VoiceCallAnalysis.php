<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VoiceCallAnalysis extends Model
{
    protected $fillable = [
        'voice_call_id',
        'intent',
        'department',
        'human_followup_required',
        'callback_requested',
        'callback_committed_by_agent',
        'live_followup_created',
        'summary',
        'unresolved_questions',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'human_followup_required' => 'boolean',
            'callback_requested' => 'boolean',
            'callback_committed_by_agent' => 'boolean',
            'live_followup_created' => 'boolean',
            'unresolved_questions' => 'array',
            'metadata' => 'array',
        ];
    }

    public function call(): BelongsTo
    {
        return $this->belongsTo(VoiceCall::class, 'voice_call_id');
    }
}
