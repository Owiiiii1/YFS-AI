<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'session_id',
    'role',
    'stage',
    'body',
    'payload',
])]
class AiPromptAnalysisMessage extends Model
{
    protected function casts(): array
    {
        return [
            'stage' => 'integer',
            'payload' => 'array',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AiPromptAnalysisSession::class, 'session_id');
    }
}
