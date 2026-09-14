<?php

namespace App\Console\Commands;

use App\Models\InstagramAccount;
use App\Services\Instagram\InstagramTokenService;
use Illuminate\Console\Command;

class RefreshInstagramTokensCommand extends Command
{
    protected $signature = 'instagram:tokens:refresh {--force : Refresh even if not due yet}';

    protected $description = 'Refresh Instagram long-lived access tokens before they expire';

    public function handle(InstagramTokenService $tokenService): int
    {
        $account = InstagramAccount::primary();
        if (! filled($account->access_token_encrypted)) {
            $this->warn('No Instagram access token configured.');

            return self::FAILURE;
        }

        $status = $tokenService->safeTokenStatus($account);
        $this->line('Token status: '.($status['status'] ?? 'unknown'));
        $this->line('Expires at: '.($status['token_expires_at'] ?? 'unknown'));

        if ($this->option('force') || $tokenService->shouldRefresh($account)) {
            $refreshed = $tokenService->refreshLongLivedToken($account->fresh());
            $account->refresh();

            if ($refreshed) {
                $this->info('Instagram token refreshed. New expiry: '.($account->token_expires_at?->toDateTimeString() ?? 'unknown'));

                return self::SUCCESS;
            }

            $this->error('Instagram token refresh failed: '.($account->token_refresh_error ?? 'unknown error'));

            return self::FAILURE;
        }

        $this->info('Token refresh not needed yet.');

        return self::SUCCESS;
    }
}
