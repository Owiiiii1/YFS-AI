<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BotReply extends Model
{
    public const TYPE_FORM_SENT = 'form_sent';

    public const TYPE_MANAGER_REQUEST = 'manager_request';

    public const TYPE_OPERATOR_NEEDED = 'operator_needed';

    public const TYPE_CLIENT_FOUND = 'client_found';

    public const TYPE_CLIENT_NOT_FOUND = 'client_not_found';

    protected $fillable = [
        'conversation_id',
        'customer_id',
        'channel',
        'participant_username',
        'type',
        'summary',
        'payload',
        'telegram_sent',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'telegram_sent' => 'boolean',
            'read_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
