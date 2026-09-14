<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'decision_trace_id',
    'conversation_id',
    'purpose',
    'connection_role',
    'provider',
    'model',
    'status',
    'latency_ms',
    'system_prompt_hash',
    'user_prompt_hash',
    'system_prompt',
    'user_prompt',
    'response_text',
    'parsed_result',
    'error_message',
    'bot_prompt_revision',
    'metadata',
])]
class AiRun extends Model
{
    protected function casts(): array
    {
        return [
            'latency_ms' => 'integer',
            'parsed_result' => 'array',
            'bot_prompt_revision' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function decisionTrace(): BelongsTo
    {
        return $this->belongsTo(BotDecisionTrace::class, 'decision_trace_id');
    }
}
