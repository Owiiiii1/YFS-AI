<?php

namespace App\Services\Meta;

use App\Models\InstagramAccount;
use Illuminate\Support\Facades\Log;

class InstagramDeauthorizeService
{
    /**
     * @return array{matched: bool, account_id: int|null}
     */
    public function handle(?string $platformUserId): array
    {
        $userId = trim((string) $platformUserId);
        if ($userId === '') {
            Log::info('meta.instagram.deauthorize', [
                'matched' => false,
                'user_id_present' => false,
            ]);

            return ['matched' => false, 'account_id' => null];
        }

        $matches = InstagramAccount::query()
            ->get()
            ->filter(fn (InstagramAccount $account): bool => $account->ownsInstagramId($userId))
            ->values();

        if ($matches->count() !== 1) {
            Log::info('meta.instagram.deauthorize', [
                'matched' => false,
                'user_id_present' => true,
                'match_count' => $matches->count(),
            ]);

            return ['matched' => false, 'account_id' => null];
        }

        $account = $matches->first();
        $this->revokeTokens($account);

        Log::info('meta.instagram.deauthorize', [
            'matched' => true,
            'account_id' => $account->id,
        ]);

        return ['matched' => true, 'account_id' => $account->id];
    }

    private function revokeTokens(InstagramAccount $account): void
    {
        $settings = is_array($account->settings) ? $account->settings : [];
        $settings['oauth_connected'] = false;

        $account->forceFill([
            'access_token_encrypted' => null,
            'token_type' => null,
            'token_expires_at' => null,
            'token_refreshed_at' => null,
            'token_refresh_failed_at' => null,
            'token_refresh_error' => null,
            'token_last_checked_at' => null,
            'is_active' => false,
            'connection_status' => InstagramAccount::STATUS_DISCONNECTED,
            'last_connection_error' => null,
            'settings' => $settings,
        ])->save();
    }
}
