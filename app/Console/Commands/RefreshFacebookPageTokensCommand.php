<?php

namespace App\Console\Commands;

use App\Models\FacebookPageAccount;
use App\Services\Meta\FacebookConnectionService;
use Illuminate\Console\Command;

class RefreshFacebookPageTokensCommand extends Command
{
    protected $signature = 'facebook:tokens:check';

    protected $description = 'Validate the connected Facebook Page access token and refresh webhook subscription';

    public function handle(FacebookConnectionService $connectionService): int
    {
        $account = FacebookPageAccount::primary();

        if (! filled($account->access_token_encrypted) || ! filled($account->facebook_page_id)) {
            $this->warn('No Facebook Page token configured.');

            return self::FAILURE;
        }

        $result = $connectionService->testWithDiagnostics($account->fresh(), true);

        if (! ($result['ok'] ?? false)) {
            $this->error($result['message'] ?? 'Facebook Page token check failed.');

            return self::FAILURE;
        }

        try {
            $connectionService->ensureWebhookSubscription($account->fresh());
            $this->info($result['message'].' Webhook subscription confirmed.');
        } catch (\Throwable $exception) {
            $this->warn($result['message'].' Webhook subscription failed: '.$exception->getMessage());
        }

        return self::SUCCESS;
    }
}
