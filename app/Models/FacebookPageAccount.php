<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'name',
    'facebook_page_id',
    'access_token_encrypted',
    'token_type',
    'token_expires_at',
    'token_refreshed_at',
    'token_refresh_failed_at',
    'token_refresh_error',
    'token_last_checked_at',
    'is_active',
    'last_webhook_at',
    'connection_status',
    'last_connection_check_at',
    'last_connection_success_at',
    'last_connection_error',
    'settings',
])]
class FacebookPageAccount extends Model
{
    public const STATUS_NOT_CONFIGURED = 'not_configured';

    public const STATUS_OAUTH_READY = 'oauth_ready';

    public const STATUS_CONNECTED = 'connected';

    public const STATUS_FAILED = 'failed';

    public const STATUS_NEEDS_RECONNECT = 'needs_reconnect';

    public const STATUS_DISCONNECTED = 'disconnected';

    public const STATUS_DISABLED = 'disabled';

    public static function primary(): self
    {
        $account = self::query()->orderBy('id')->first();

        if ($account) {
            return $account;
        }

        return self::query()->create([
            'name' => 'Primary Facebook Page',
            'is_active' => false,
            'connection_status' => self::STATUS_NOT_CONFIGURED,
        ]);
    }

    public function isConnected(): bool
    {
        return (bool) (
            $this->is_active
            && filled($this->facebook_page_id)
            && filled($this->access_token_encrypted)
            && (! $this->token_expires_at || $this->token_expires_at->isFuture())
            && ($this->connection_status === self::STATUS_CONNECTED)
        );
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'access_token_encrypted' => 'encrypted',
            'token_type' => 'string',
            'token_expires_at' => 'datetime',
            'token_refreshed_at' => 'datetime',
            'token_refresh_failed_at' => 'datetime',
            'token_last_checked_at' => 'datetime',
            'last_webhook_at' => 'datetime',
            'last_connection_check_at' => 'datetime',
            'last_connection_success_at' => 'datetime',
            'settings' => 'array',
        ];
    }
}
