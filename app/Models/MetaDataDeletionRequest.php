<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'confirmation_code',
    'platform_user_id',
    'status',
    'requested_at',
    'completed_at',
])]
class MetaDataDeletionRequest extends Model
{
    public const STATUS_RECEIVED = 'received';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUSES = [
        self::STATUS_RECEIVED,
        self::STATUS_PROCESSING,
        self::STATUS_COMPLETED,
    ];

    public function publicStatusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PROCESSING => 'Processing',
            self::STATUS_COMPLETED => 'Completed',
            default => 'Request received',
        };
    }

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
