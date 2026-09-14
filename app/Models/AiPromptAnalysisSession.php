<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id',
    'parent_session_id',
    'conversation_id',
    'target_username',
    'status',
    'step',
    'question',
    'context_summary',
    'bot_revision_at_start',
    'analysis_payload',
    'user_instruction',
    'error_message',
    'finished_at',
])]
class AiPromptAnalysisSession extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_ANALYZING = 'analyzing';
    public const STATUS_ANALYZED = 'analyzed';
    public const STATUS_PREVIEW_READY = 'preview_ready';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_APPLIED = 'applied';
    public const STATUS_FINISHED = 'finished';
    public const STATUS_FAILED = 'failed';

    protected function casts(): array
    {
        return [
            'step' => 'integer',
            'bot_revision_at_start' => 'integer',
            'analysis_payload' => 'array',
            'finished_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AiPromptAnalysisMessage::class, 'session_id');
    }

    public function proposals(): HasMany
    {
        return $this->hasMany(AiPromptChangeProposal::class, 'session_id');
    }
}
