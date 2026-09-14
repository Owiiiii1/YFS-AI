<?php

namespace App\Services\Meta;

use App\Models\FacebookPageAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class FacebookConnectionService
{
    public function __construct(
        private readonly MetaFacebookOAuthService $oauthService,
        private readonly MetaFacebookMessageSender $messageSender,
    ) {}

    /**
     * @return array{ok: bool, message: string}
     */
    public function testWithDiagnostics(FacebookPageAccount $account, bool $persist = true): array
    {
        $account->forceFill(['last_connection_check_at' => now()])->save();

        if (! filled($account->facebook_page_id) || ! filled($account->access_token_encrypted)) {
            $message = 'Facebook Page id or access token is missing.';
            if ($persist) {
                $this->markFailed($account, $message);
            }

            return ['ok' => false, 'message' => $message];
        }

        $response = Http::timeout(20)
            ->acceptJson()
            ->get($this->graphEndpoint('/'.$account->facebook_page_id), [
                'access_token' => (string) $account->access_token_encrypted,
                'fields' => 'id,name',
            ]);

        if (! $response->successful()) {
            $message = (string) data_get($response->json(), 'error.message', 'Facebook Page token check failed.');
            if ($persist) {
                $this->markFailed($account, $message);
            }

            return ['ok' => false, 'message' => $message];
        }

        $settings = is_array($account->settings) ? $account->settings : [];
        $settings['facebook_page_name'] = data_get($response->json(), 'name', data_get($settings, 'facebook_page_name'));

        $account->forceFill([
            'connection_status' => FacebookPageAccount::STATUS_CONNECTED,
            'is_active' => true,
            'last_connection_success_at' => now(),
            'last_connection_error' => null,
            'token_last_checked_at' => now(),
            'settings' => $settings,
        ])->save();

        $canSend = $this->messageSender->canSend($account->fresh());

        return [
            'ok' => true,
            'message' => $canSend
                ? 'Facebook Page connection is healthy and can send messages.'
                : 'Facebook Page token is valid, but send endpoint could not be resolved.',
        ];
    }

    public function markFailed(FacebookPageAccount $account, string $message): void
    {
        $account->forceFill([
            'connection_status' => FacebookPageAccount::STATUS_FAILED,
            'last_connection_error' => $message,
            'last_connection_check_at' => now(),
        ])->save();
    }

    public function disconnect(FacebookPageAccount $account): void
    {
        $settings = is_array($account->settings) ? $account->settings : [];
        unset(
            $settings['oauth_connected'],
            $settings['webhook_subscribed_at'],
            $settings['webhook_subscribed_fields'],
            $settings['available_pages'],
        );

        $account->forceFill([
            'access_token_encrypted' => null,
            'facebook_page_id' => null,
            'token_type' => null,
            'token_expires_at' => null,
            'token_refreshed_at' => null,
            'token_refresh_failed_at' => null,
            'token_refresh_error' => null,
            'is_active' => false,
            'connection_status' => FacebookPageAccount::STATUS_DISCONNECTED,
            'last_connection_error' => null,
            'settings' => $settings,
        ])->save();
    }

    public function ensureWebhookSubscription(FacebookPageAccount $account): void
    {
        if (! $account->isConnected()) {
            throw new RuntimeException('Facebook Page is not connected.');
        }

        $ok = $this->oauthService->subscribePageToWebhooks(
            (string) $account->facebook_page_id,
            (string) $account->access_token_encrypted,
        );

        if (! $ok) {
            throw new RuntimeException('Could not subscribe the Facebook Page to Messenger webhooks.');
        }

        $settings = is_array($account->settings) ? $account->settings : [];
        $settings['webhook_subscribed_at'] = now()->toIso8601String();
        $settings['webhook_subscribed_fields'] = 'messages,messaging_postbacks';
        $account->forceFill(['settings' => $settings])->save();
    }

    private function graphEndpoint(string $path): string
    {
        $base = rtrim((string) config('services.meta.graph_base_url', 'https://graph.facebook.com'), '/');
        $version = trim((string) config('services.meta.graph_api_version', 'v25.0'), '/');

        return $base.'/'.$version.'/'.ltrim($path, '/');
    }
}
