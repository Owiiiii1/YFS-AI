<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'bot_setting_id',
    'revision',
    'prompt_config',
    'changed_by',
    'reason',
])]
class BotPromptRevision extends Model
{
    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'prompt_config' => 'array',
        ];
    }

    public function botSetting(): BelongsTo
    {
        return $this->belongsTo(BotSetting::class);
    }
}
