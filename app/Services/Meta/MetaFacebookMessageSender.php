<?php

namespace App\Services\Meta;

use App\Models\FacebookPageAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class MetaFacebookMessageSender
{
    public const MAX_TEXT_LENGTH = 2000;

    public function canSend(FacebookPageAccount $account): bool
    {
        try {
            $this->resolveSendUrl($account);

            return $account->isConnected() && filled($account->access_token_encrypted);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return list<string>
     */
    public function sendTextMessage(
        FacebookPageAccount $account,
        string $recipientPsid,
        string $text,
        bool $asHumanAgent = false,
    ): array {
        $text = trim($text);
        if ($text === '') {
            throw new RuntimeException('Cannot send an empty Facebook message.');
        }

        if (! $account->isConnected()) {
            throw new RuntimeException('Facebook Page is not connected.');
        }

        $chunks = $this->splitText($text);
        $messageIds = [];

        foreach ($chunks as $chunk) {
            $messageIds[] = $this->postTextMessage($account, $recipientPsid, $chunk, $asHumanAgent);
        }

        return $messageIds;
    }

    private function postTextMessage(
        FacebookPageAccount $account,
        string $recipientPsid,
        string $text,
        bool $asHumanAgent = false,
    ): string {
        $url = $this->resolveSendUrl($account);
        $token = (string) $account->access_token_encrypted;

        $payload = [
            'recipient' => ['id' => $recipientPsid],
            'message' => ['text' => $text],
        ];

        if ($asHumanAgent) {
            $payload['messaging_type'] = 'MESSAGE_TAG';
            $payload['tag'] = 'HUMAN_AGENT';
        } else {
            $payload['messaging_type'] = 'RESPONSE';
        }

        $response = Http::timeout(20)
            ->acceptJson()
            ->withToken($token)
            ->post($url, $payload);

        if (! $response->successful()) {
            $message = (string) (
                data_get($response->json(), 'error.message')
                ?: data_get($response->json(), 'error.error_user_msg')
                ?: 'Facebook send failed with status '.$response->status()
            );

            Log::warning('Facebook outbound message failed.', [
                'url' => $url,
                'status' => $response->status(),
                'error' => $message,
                'length' => mb_strlen($text),
                'as_human_agent' => $asHumanAgent,
            ]);

            throw new RuntimeException($message);
        }

        return (string) (data_get($response->json(), 'message_id') ?? data_get($response->json(), 'id') ?? '');
    }

    /**
     * @return list<string>
     */
    public function splitText(string $text): array
    {
        $text = trim($text);
        $max = self::MAX_TEXT_LENGTH - 20;

        if (mb_strlen($text) <= $max) {
            return [$text];
        }

        $chunks = [];
        $remaining = $text;

        while ($remaining !== '') {
            if (mb_strlen($remaining) <= $max) {
                $chunks[] = $remaining;
                break;
            }

            $window = mb_substr($remaining, 0, $max);
            $breakAt = max(
                mb_strrpos($window, "\n\n") ?: 0,
                mb_strrpos($window, "\n") ?: 0,
                mb_strrpos($window, '. ') ?: 0,
                mb_strrpos($window, '! ') ?: 0,
                mb_strrpos($window, '? ') ?: 0,
                mb_strrpos($window, ' ') ?: 0,
            );

            if ($breakAt < (int) ($max * 0.4)) {
                $breakAt = $max;
            }

            $chunk = trim(mb_substr($remaining, 0, $breakAt));
            if ($chunk === '') {
                $chunk = trim(mb_substr($remaining, 0, $max));
                $breakAt = mb_strlen($chunk);
            }

            $chunks[] = $chunk;
            $remaining = trim(mb_substr($remaining, $breakAt));
        }

        return $chunks !== [] ? $chunks : [mb_substr($text, 0, $max)];
    }

    public function resolveSendUrl(FacebookPageAccount $account): string
    {
        $pageId = trim((string) $account->facebook_page_id);
        if ($pageId === '') {
            throw new RuntimeException('Facebook Page id is missing for outbound messaging.');
        }

        $base = rtrim((string) config('services.meta.graph_base_url', 'https://graph.facebook.com'), '/');
        $version = trim((string) config('services.meta.graph_api_version', 'v25.0'), '/');

        return $base.'/'.$version.'/'.$pageId.'/messages';
    }
}
