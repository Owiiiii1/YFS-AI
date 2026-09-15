<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VoiceContact extends Model
{
    protected $fillable = [
        'phone_normalized',
        'phone_display',
        'name',
        'preferred_language',
        'first_called_at',
        'last_called_at',
        'calls_count',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'first_called_at' => 'datetime',
            'last_called_at' => 'datetime',
            'calls_count' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function calls(): HasMany
    {
        return $this->hasMany(VoiceCall::class);
    }
}
