<?php

namespace App\Models;

use App\Support\VoiceAssistantSettingCatalog;
use Illuminate\Database\Eloquent\Model;

class VoiceAssistantSetting extends Model
{
    protected $fillable = [
        'key',
        'title',
        'instructions',
        'enabled',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Insert catalog sections that are missing. Never overwrite admin edits.
     */
    public static function ensureDefaults(): void
    {
        foreach (VoiceAssistantSettingCatalog::sections() as $section) {
            self::query()->firstOrCreate(
                ['key' => $section['key']],
                [
                    'title' => $section['title'],
                    'instructions' => $section['instructions'],
                    'enabled' => true,
                    'sort_order' => $section['sort_order'],
                ],
            );
        }
    }
}
