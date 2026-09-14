<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'conversation_id',
    'channel',
    'trigger_inbound_message_id',
    'trigger_inbound_message_ids',
    'effective_customer_text',
    'status',
    'reply_source',
    'decision_path',
    'template_key',
    'skip_reason',
    'conversation_status',
    'intake_status',
    'intake_data_snapshot',
    'language',
    'bot_prompt_revision',
    'flavor_prompt_included',
    'bot_enabled',
    'outbound_message_ids',
    'order_id',
    'metadata',
    'completed_at',
])]
class BotDecisionTrace extends Model
{
    protected function casts(): array
    {
        return [
            'trigger_inbound_message_ids' => 'array',
            'decision_path' => 'array',
            'intake_data_snapshot' => 'array',
            'flavor_prompt_included' => 'boolean',
            'bot_enabled' => 'boolean',
            'outbound_message_ids' => 'array',
            'metadata' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function aiRuns(): HasMany
    {
        return $this->hasMany(AiRun::class, 'decision_trace_id');
    }
}
