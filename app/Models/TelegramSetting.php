<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'bot_token_encrypted',
    'bot_id',
    'bot_username',
    'bot_name',
    'bot_status',
    'webhook_secret',
    'webhook_set_at',
    'channel_id',
    'channel_thread_id',
    'channel_username',
    'channel_title',
    'channel_status',
    'last_error',
])]
class TelegramSetting extends Model
{
    public const STATUS_NOT_CONFIGURED = 'not_configured';

    public const STATUS_CONNECTED = 'connected';

    public const STATUS_FAILED = 'failed';

    public static function current(): self
    {
        $row = self::query()->orderBy('id')->first();

        if ($row) {
            return $row;
        }

        return self::query()->create([
            'bot_status' => self::STATUS_NOT_CONFIGURED,
            'channel_status' => self::STATUS_NOT_CONFIGURED,
        ]);
    }

    public function isBotConnected(): bool
    {
        return $this->bot_status === self::STATUS_CONNECTED && filled($this->bot_token_encrypted);
    }

    public function isChannelConnected(): bool
    {
        return $this->channel_status === self::STATUS_CONNECTED && filled($this->channel_id);
    }

    protected function casts(): array
    {
        return [
            'bot_token_encrypted' => 'encrypted',
            'webhook_set_at' => 'datetime',
            'channel_thread_id' => 'integer',
        ];
    }
}
