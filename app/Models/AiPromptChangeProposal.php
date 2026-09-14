<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'session_id',
    'base_revision',
    'status',
    'proposed_patch',
    'preview_before',
    'preview_after',
    'sensitive_changes',
    'validation_results',
    'approved_by',
    'approved_at',
    'applied_revision',
    'applied_at',
])]
class AiPromptChangeProposal extends Model
{
    protected function casts(): array
    {
        return [
            'base_revision' => 'integer',
            'proposed_patch' => 'array',
            'preview_before' => 'array',
            'preview_after' => 'array',
            'sensitive_changes' => 'array',
            'validation_results' => 'array',
            'approved_at' => 'datetime',
            'applied_revision' => 'integer',
            'applied_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AiPromptAnalysisSession::class, 'session_id');
    }
}
