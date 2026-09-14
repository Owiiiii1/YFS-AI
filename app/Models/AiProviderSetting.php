<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiProviderSetting extends Model
{
    public const PROVIDER_ELEVENLABS = 'elevenlabs';

    public const LLM_PROVIDERS = ['openai', 'anthropic', 'gemini'];

    protected $fillable = [
        'provider',
        'label',
        'api_key',
        'is_connected',
        'is_active',
        'active_model',
        'available_models',
        'last_checked_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'available_models' => 'array',
            'is_connected' => 'boolean',
            'is_active' => 'boolean',
            'last_checked_at' => 'datetime',
        ];
    }
}
