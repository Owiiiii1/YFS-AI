<?php

namespace App\Services\Meta;

use App\Jobs\ProcessIncomingInstagramMessageJob;
use App\Models\InstagramAccount;
use App\Services\Instagram\InstagramMediaResolver;
use App\Services\Instagram\ProcessOutgoingInstagramEchoService;
use Illuminate\Support\Facades\Log;

class MetaInstagramWebhookService
{
    public function __construct(
        private readonly ProcessOutgoingInstagramEchoService $echoService,
        private readonly InstagramMediaResolver $mediaResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload): void
    {
        $object = (string) ($payload['object'] ?? '');

        Log::info('Instagram webhook payload received.', [
            'object' => $object,
            'entries' => count((array) ($payload['entry'] ?? [])),
        ]);

        // Page/Messenger events belong to /api/webhooks/meta/facebook — never treat them as Instagram.
        if ($object !== 'instagram') {
            Log::warning('Instagram webhook ignored: unsupported object.', [
                'object' => $object,
            ]);

            return;
        }

        $account = InstagramAccount::primary();
        $ownedIds = $account->ownedInstagramIds();
        $handled = false;

        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $entryId = trim((string) ($entry['id'] ?? ''));
            if ($ownedIds !== [] && $entryId !== '' && ! $account->ownsInstagramId($entryId)) {
                Log::info('Instagram webhook entry skipped: ig user id mismatch.', [
                    'entry_id' => $entryId,
                    'expected_ig_user_ids' => $ownedIds,
                ]);

                continue;
            }

            foreach ((array) ($entry['messaging'] ?? []) as $event) {
                if (is_array($event)) {
                    $this->dispatchMessagingEvent($event);
                    $handled = true;
                }
            }

            foreach ((array) ($entry['changes'] ?? []) as $change) {
                if (! is_array($change)) {
                    continue;
                }

                if (($change['field'] ?? '') === 'messages') {
                    $value = $change['value'] ?? [];
                    if (is_array($value)) {
                        $this->dispatchMessagingEvent($value);
                        $handled = true;
                    }
                }
            }
        }

        $account = InstagramAccount::primary();
        $account->forceFill(['last_webhook_at' => now()])->save();

        if (! $handled) {
            Log::info('Instagram webhook received but no messaging events were found.');
        }
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function dispatchMessagingEvent(array $event): void
    {
        $message = is_array($event['message'] ?? null) ? $event['message'] : [];
        $senderId = trim((string) data_get($event, 'sender.id', ''));
        $messageId = trim((string) (data_get($event, 'message.mid') ?: data_get($event, 'message.id', '')));
        $text = trim((string) data_get($event, 'message.text', ''));
        $attachments = $this->mediaResolver->extractImageAttachments($message);

        Log::info('Instagram webhook event.', [
            'sender_id' => $senderId,
            'message_id' => $messageId !== '' ? $messageId : null,
            'has_text' => $text !== '',
            'text_preview' => $text !== '' ? mb_substr($text, 0, 80) : null,
            'image_attachments' => count($attachments),
            'raw_attachment_count' => is_array($message['attachments'] ?? null) ? count($message['attachments']) : 0,
            'is_echo' => ! empty($message['is_echo']),
            'is_deleted' => ! empty($message['is_deleted']),
            'is_unsupported' => ! empty($message['is_unsupported']),
            'has_postback' => isset($event['postback']),
            'has_referral' => isset($event['referral']),
            'has_reaction' => isset($event['reaction']),
            'has_read' => isset($event['read']),
            'event_keys' => array_values(array_keys($event)),
        ]);

        if (! isset($event['message']) && ! isset($event['postback'])) {
            Log::info('Instagram webhook event ignored: no message or postback.', [
                'sender_id' => $senderId,
                'event_keys' => array_values(array_keys($event)),
            ]);

            return;
        }

        if (! empty($event['message']['is_deleted'])) {
            return;
        }

        if (! empty($event['message']['is_echo'])) {
            $this->dispatchEchoEvent($event);

            return;
        }

        $resolveMedia = $attachments === [] && $this->mediaResolver->looksLikeUnresolvedMedia($message);

        // Icebreaker / CTA button clicks arrive as postbacks, not message.text.
        if ($text === '' && $attachments === [] && is_array($event['postback'] ?? null)) {
            $postbackTitle = trim((string) data_get($event, 'postback.title', ''));
            $postbackPayload = trim((string) data_get($event, 'postback.payload', ''));
            $text = $postbackTitle !== '' ? $postbackTitle : $postbackPayload;
            $messageId = trim((string) (data_get($event, 'postback.mid') ?: $messageId));

            Log::info('Instagram webhook postback treated as inbound.', [
                'sender_id' => $senderId,
                'title' => $postbackTitle,
                'has_payload' => $postbackPayload !== '',
            ]);
        }

        if ($messageId === '' && $senderId !== '' && ($text !== '' || $attachments !== [] || $resolveMedia)) {
            $messageId = 'fallback-'.sha1(json_encode([
                $senderId,
                data_get($event, 'timestamp'),
                $text,
                $attachments,
            ]));
        }

        // Photo-only DMs often have a mid and empty text. Dispatch them so the
        // processor can Graph-lookup image_data.url when the webhook omitted it.
        $hasInbound = $text !== '' || $attachments !== [] || ($resolveMedia && $messageId !== '');
        if ($text === '' && $attachments === [] && $messageId !== '' && isset($event['message'])) {
            $hasInbound = true;
            $resolveMedia = true;
        }

        if ($senderId === '' || $messageId === '' || ! $hasInbound) {
            Log::info('Instagram webhook messaging event skipped.', [
                'sender_id' => $senderId,
                'message_id' => $messageId,
                'has_postback' => isset($event['postback']),
                'has_referral' => isset($event['referral']),
                'is_unsupported' => ! empty($message['is_unsupported']),
                'attachment_count' => is_array($message['attachments'] ?? null) ? count($message['attachments']) : 0,
            ]);

            return;
        }

        $account = InstagramAccount::primary();
        if ($account->ownsInstagramId($senderId)) {
            Log::info('Instagram webhook inbound skipped: sender is the business account.', [
                'sender_id' => $senderId,
                'message_id' => $messageId,
            ]);

            return;
        }

        ProcessIncomingInstagramMessageJob::dispatch([
            'sender_id' => $senderId,
            'message_id' => $messageId,
            'text' => $text,
            'timestamp' => data_get($event, 'timestamp'),
            'attachments' => $attachments,
            'resolve_media' => $resolveMedia,
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

        $account = InstagramAccount::primary();
        if ($account->ownsInstagramId($recipientId)) {
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
