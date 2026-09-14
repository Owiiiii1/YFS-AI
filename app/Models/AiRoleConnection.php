<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'role',
    'provider',
    'key_source_provider',
    'active_model',
    'is_connected',
    'last_checked_at',
    'last_error',
])]
class AiRoleConnection extends Model
{
    public const ROLE_BOT_RUNTIME = 'bot_runtime';

    public const ROLE_PROMPT_ANALYSIS = 'prompt_analysis';

    protected function casts(): array
    {
        return [
            'is_connected' => 'boolean',
            'last_checked_at' => 'datetime',
        ];
    }
}
