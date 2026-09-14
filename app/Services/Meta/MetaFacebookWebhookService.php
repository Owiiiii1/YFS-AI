<?php

namespace App\Services\Meta;

use App\Jobs\ProcessIncomingFacebookMessageJob;
use App\Models\FacebookPageAccount;
use App\Services\Facebook\ProcessOutgoingFacebookEchoService;
use Illuminate\Support\Facades\Log;

class MetaFacebookWebhookService
{
    public function __construct(
        private readonly ProcessOutgoingFacebookEchoService $echoService,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload): void
    {
        $object = (string) ($payload['object'] ?? '');

        Log::info('Facebook webhook payload received.', [
            'object' => $object,
            'entries' => count((array) ($payload['entry'] ?? [])),
        ]);

        if ($object !== 'page') {
            Log::warning('Facebook webhook ignored: unsupported object.', [
                'object' => $object,
            ]);

            return;
        }

        $account = FacebookPageAccount::primary();
        $pageId = trim((string) ($account->facebook_page_id ?? ''));
        $handled = false;

        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $entryId = trim((string) ($entry['id'] ?? ''));
            if ($pageId !== '' && $entryId !== '' && $entryId !== $pageId) {
                Log::info('Facebook webhook entry skipped: page id mismatch.', [
                    'entry_id' => $entryId,
                    'expected_page_id' => $pageId,
                ]);

                continue;
            }

            foreach ((array) ($entry['messaging'] ?? []) as $event) {
                if (is_array($event)) {
                    $this->dispatchMessagingEvent($event);
                    $handled = true;
                }
            }
        }

        $account->forceFill(['last_webhook_at' => now()])->save();

        if (! $handled) {
            Log::info('Facebook webhook received but no messaging events were found.');
        }
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function dispatchMessagingEvent(array $event): void
    {
        if (! empty($event['message']['is_deleted'])) {
            return;
        }

        if (! empty($event['message']['is_echo'])) {
            $this->dispatchEchoEvent($event);

            return;
        }

        $senderId = trim((string) data_get($event, 'sender.id', ''));
        $messageId = trim((string) (data_get($event, 'message.mid') ?: data_get($event, 'message.id', '')));
        $text = trim((string) data_get($event, 'message.text', ''));
        $attachments = collect((array) data_get($event, 'message.attachments', []))
            ->filter(fn (mixed $attachment): bool => is_array($attachment))
            ->map(static fn (array $attachment): array => [
                'type' => (string) ($attachment['type'] ?? ''),
                'url' => trim((string) data_get($attachment, 'payload.url', '')),
                'mime' => data_get($attachment, 'payload.mime_type'),
            ])
            ->filter(static fn (array $attachment): bool => $attachment['type'] === 'image' && $attachment['url'] !== '')
            ->values()
            ->all();

        if ($text === '' && $attachments === [] && is_array($event['postback'] ?? null)) {
            $postbackTitle = trim((string) data_get($event, 'postback.title', ''));
            $postbackPayload = trim((string) data_get($event, 'postback.payload', ''));
            $text = $postbackTitle !== '' ? $postbackTitle : $postbackPayload;
            $messageId = trim((string) (data_get($event, 'postback.mid') ?: $messageId));
        }

        if ($messageId === '' && $senderId !== '' && ($text !== '' || $attachments !== [])) {
            $messageId = 'fallback-'.sha1(json_encode([
                $senderId,
                data_get($event, 'timestamp'),
                $text,
                $attachments,
            ]));
        }

        if ($senderId === '' || $messageId === '' || ($text === '' && $attachments === [])) {
            Log::info('Facebook webhook messaging event skipped.', [
                'sender_id' => $senderId,
                'message_id' => $messageId,
                'has_text' => $text !== '',
                'attachment_count' => count($attachments),
                'has_postback' => isset($event['postback']),
                'event_keys' => array_values(array_keys($event)),
            ]);

            return;
        }

        $account = FacebookPageAccount::primary();
        if (filled($account->facebook_page_id) && $senderId === (string) $account->facebook_page_id) {
            return;
        }

        ProcessIncomingFacebookMessageJob::dispatch([
            'sender_id' => $senderId,
            'message_id' => $messageId,
            'text' => $text,
            'timestamp' => data_get($event, 'timestamp'),
            'attachments' => $attachments,
        ]);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function dispatchEchoEvent(array $event): void
    {
        $recipientId = trim((string) data_get($event, 'recipient.id', ''));
        $messageId = trim((string) data_get($event, 'message.mid', ''));
        $text = trim((string) data_get($event, 'message.text', ''));

        if ($recipientId === '' || $messageId === '') {
            return;
        }

        $this->echoService->handle([
            'recipient_id' => $recipientId,
            'message_id' => $messageId,
            'text' => $text,
            'timestamp' => data_get($event, 'timestamp'),
        ]);
    }
}
