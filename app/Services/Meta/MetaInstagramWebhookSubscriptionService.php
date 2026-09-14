<?php

namespace App\Services\Meta;

use App\Models\InstagramAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class MetaInstagramWebhookSubscriptionService
{
    /**
     * @return list<string>
     */
    public function defaultFields(): array
    {
        return ['messages', 'messaging_postbacks', 'messaging_seen'];
    }

    public function subscribe(InstagramAccount $account, ?array $fields = null): bool
    {
        if (! $account->isConnected()) {
            throw new RuntimeException('Instagram account is not connected.');
        }

        $fields = $fields ?? $this->defaultFields();
        $token = (string) $account->access_token_encrypted;
        $igUserId = trim((string) $account->instagram_user_id);

        if ($token === '' || $igUserId === '') {
            throw new RuntimeException('Instagram account is missing user id or access token.');
        }

        $base = rtrim((string) config('services.meta.instagram_graph_base_url', 'https://graph.instagram.com'), '/');
        $version = trim((string) config('services.meta.graph_api_version', 'v25.0'), '/');
        $url = $base.'/'.$version.'/'.$igUserId.'/subscribed_apps';

        $response = Http::timeout(20)
            ->acceptJson()
            ->post($url, [
                'subscribed_fields' => implode(',', $fields),
                'access_token' => $token,
            ]);

        if (! $response->successful() || ! (bool) data_get($response->json(), 'success', false)) {
            $message = (string) (
                data_get($response->json(), 'error.message')
                ?: 'Instagram webhook subscription failed with status '.$response->status()
            );

            Log::warning('Instagram subscribed_apps failed.', [
                'status' => $response->status(),
                'error' => $message,
                'body' => $response->body(),
            ]);

            throw new RuntimeException($message);
        }

        $settings = is_array($account->settings) ? $account->settings : [];
        $settings['webhook_subscribed_fields'] = $fields;
        $settings['webhook_subscribed_at'] = now()->toIso8601String();
        $account->forceFill(['settings' => $settings])->save();

        return true;
    }
}
