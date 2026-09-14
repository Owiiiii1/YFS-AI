<?php

namespace App\Services\Meta;

use App\Models\InstagramAccount;
use App\Services\Instagram\InstagramTokenService;
use App\Support\InstagramOutboundLinkButtons;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class MetaInstagramMessageSender
{
    public const MAX_TEXT_LENGTH = 1000;

    public function __construct(
        private readonly InstagramTokenService $tokenService,
    ) {}

    public function canSend(InstagramAccount $account): bool
    {
        try {
            $this->resolveSendUrl($account);

            return $account->isConnected() && filled($account->access_token_encrypted);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return list<string> Instagram message ids for each sent chunk
     */
    public function sendTextMessage(
        InstagramAccount $account,
        string $recipientInstagramScopedId,
        string $text,
        bool $asHumanAgent = false,
    ): array {
        $text = trim($text);
        if ($text === '') {
            throw new RuntimeException('Cannot send an empty Instagram message.');
        }

        if (! $account->isConnected()) {
            throw new RuntimeException('Instagram account is not connected.');
        }

        $account = $this->tokenService->ensureFreshToken($account);
        $chunks = $this->splitTextForInstagram($text);
        $messageIds = [];

        foreach ($chunks as $chunk) {
            $messageIds[] = $this->postMessage(
                $account,
                $recipientInstagramScopedId,
                ['text' => $chunk],
                $asHumanAgent,
            );
        }

        return $messageIds;
    }

    /**
     * Send a bot reply as plain text plus tappable Instagram generic-template buttons.
     * Every https URL is stripped from the text bubble and delivered as a web_url button.
     *
     * @param  array<string, mixed>  $formConfig
     * @param  list<string>  $omitLinkKinds  link kinds already sent in this conversation
     * @return list<array{id: string, body: string}>
     */
    public function sendReplyWithFormButtons(
        InstagramAccount $account,
        string $recipientInstagramScopedId,
        string $text,
        string $locale = 'ru',
        bool $asHumanAgent = false,
        array $formConfig = [],
        array $omitLinkKinds = [],
    ): array {
        $bundle = InstagramOutboundLinkButtons::fromReply(trim($text), $locale, $formConfig, true, $omitLinkKinds);
        $plain = $bundle['plain'];
        $parts = [];

        if ($plain === '' && $bundle['cards'] === []) {
            throw new RuntimeException('Cannot send an empty Instagram message.');
        }

        if ($plain !== '') {
            $chunks = $this->splitTextForInstagram($plain);
            $messageIds = $this->sendTextMessage(
                $account,
                $recipientInstagramScopedId,
                $plain,
                $asHumanAgent,
            );
            foreach ($chunks as $index => $chunk) {
                $parts[] = [
                    'id' => (string) ($messageIds[$index] ?? ''),
                    'body' => $chunk,
                ];
            }
        }

        foreach ($bundle['cards'] as $card) {
            $messageId = $this->sendGenericButtons(
                $account,
                $recipientInstagramScopedId,
                $card,
                $asHumanAgent,
            );
            $parts[] = [
                'id' => $messageId,
                'body' => $this->cardBodyForLog($card),
            ];
        }

        return $parts;
    }

    /**
     * @param  array{title: string, subtitle: string, buttons: list<array{title: string, url: string}>}  $card
     */
    public function sendGenericButtons(
        InstagramAccount $account,
        string $recipientInstagramScopedId,
        array $card,
        bool $asHumanAgent = false,
    ): string {
        if (! $account->isConnected()) {
            throw new RuntimeException('Instagram account is not connected.');
        }

        $buttons = [];
        foreach ($card['buttons'] as $button) {
            $title = mb_substr(trim((string) ($button['title'] ?? '')), 0, InstagramOutboundLinkButtons::BUTTON_TITLE_MAX);
            $url = trim((string) ($button['url'] ?? ''));
            if ($title === '' || $url === '') {
                continue;
            }
            $buttons[] = [
                'type' => 'web_url',
                'url' => $url,
                'title' => $title,
            ];
        }

        if ($buttons === []) {
            throw new RuntimeException('Instagram link card has no valid buttons.');
        }

        $account = $this->tokenService->ensureFreshToken($account);

        return $this->postMessage(
            $account,
            $recipientInstagramScopedId,
            [
                'attachment' => [
                    'type' => 'template',
                    'payload' => [
                        'template_type' => 'generic',
                        'elements' => [[
                            'title' => mb_substr((string) $card['title'], 0, InstagramOutboundLinkButtons::CARD_TITLE_MAX),
                            'subtitle' => mb_substr((string) ($card['subtitle'] ?? ''), 0, InstagramOutboundLinkButtons::CARD_SUBTITLE_MAX),
                            'buttons' => $buttons,
                        ]],
                    ],
                ],
            ],
            $asHumanAgent,
        );
    }

    /**
     * @param  array{title: string, subtitle: string, button: string}  $copy
     */
    public function sendGenericUrlButton(
        InstagramAccount $account,
        string $recipientInstagramScopedId,
        string $url,
        array $copy,
        bool $asHumanAgent = false,
    ): string {
        return $this->sendGenericButtons(
            $account,
            $recipientInstagramScopedId,
            [
                'title' => (string) ($copy['title'] ?? 'Young Fashion Show'),
                'subtitle' => (string) ($copy['subtitle'] ?? ''),
                'buttons' => [[
                    'title' => (string) ($copy['button'] ?? 'Open'),
                    'url' => $url,
                ]],
            ],
            $asHumanAgent,
        );
    }

    /**
     * @param  array{title?: string, buttons: list<array{title: string, url: string}>}  $card
     */
    private function cardBodyForLog(array $card): string
    {
        $lines = [];
        foreach ($card['buttons'] as $button) {
            $lines[] = trim((string) ($button['title'] ?? '')).': '.trim((string) ($button['url'] ?? ''));
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function postMessage(
        InstagramAccount $account,
        string $recipientInstagramScopedId,
        array $message,
        bool $asHumanAgent = false,
    ): string {
        $url = $this->resolveSendUrl($account);
        $token = (string) $account->access_token_encrypted;

        $payload = [
            'recipient' => ['id' => $recipientInstagramScopedId],
            'message' => $message,
        ];

        // HUMAN_AGENT is for live operator replies only (extends window to ~7 days).
        // Never use for bot / automated messages.
        if ($asHumanAgent) {
            $payload['messaging_type'] = 'MESSAGE_TAG';
            $payload['tag'] = 'HUMAN_AGENT';
        }

        $response = Http::timeout(20)
            ->acceptJson()
            ->withToken($token)
            ->post($url, $payload);

        if (! $response->successful()) {
            $error = (string) (
                data_get($response->json(), 'error.message')
                ?: data_get($response->json(), 'error.error_user_msg')
                ?: 'Instagram send failed with status '.$response->status()
            );

            Log::warning('Instagram outbound message failed.', [
                'url' => $url,
                'status' => $response->status(),
                'error' => $error,
                'as_human_agent' => $asHumanAgent,
                'has_attachment' => isset($message['attachment']),
            ]);

            throw new RuntimeException($error);
        }

        return (string) (data_get($response->json(), 'message_id') ?? data_get($response->json(), 'id') ?? '');
    }

    /**
     * @return list<string>
     */
    public function splitTextForInstagram(string $text): array
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

    public function resolveSendUrl(InstagramAccount $account): string
    {
        $configured = trim((string) config('services.meta.instagram_messaging_send_path', ''));

        if ($configured !== '') {
            if (str_starts_with($configured, 'http://') || str_starts_with($configured, 'https://')) {
                return rtrim($configured, '/');
            }

            $base = rtrim((string) config('services.meta.instagram_graph_base_url', 'https://graph.instagram.com'), '/');
            $version = trim((string) config('services.meta.graph_api_version', 'v25.0'), '/');

            return $base.'/'.$version.'/'.ltrim($configured, '/');
        }

        $igUserId = trim((string) $account->instagram_user_id);
        if ($igUserId === '') {
            throw new RuntimeException('Instagram user id is missing for outbound messaging.');
        }

        $base = rtrim((string) config('services.meta.instagram_graph_base_url', 'https://graph.instagram.com'), '/');
        $version = trim((string) config('services.meta.graph_api_version', 'v25.0'), '/');

        return $base.'/'.$version.'/'.$igUserId.'/messages';
    }
}
