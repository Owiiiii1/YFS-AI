<?php

namespace App\Services\Instagram;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class InstagramMediaResolver
{
    /**
     * Collect downloadable image attachments from a webhook `message` object
     * or a Graph API message payload.
     *
     * @param  array<string, mixed>  $source
     * @return list<array{type: string, url: string, mime: string|null}>
     */
    public function extractImageAttachments(array $source): array
    {
        $found = [];

        foreach ($this->attachmentRows($source['attachments'] ?? null) as $attachment) {
            $url = $this->imageUrlFromAttachment($attachment);
            if ($url === '') {
                continue;
            }

            $found[$url] = [
                'type' => 'image',
                'url' => $url,
                'mime' => $this->mimeFromAttachment($attachment),
            ];
        }

        foreach ($this->attachmentRows(data_get($source, 'shares')) as $share) {
            $url = trim((string) ($share['link'] ?? $share['url'] ?? ''));
            if ($url === '' || ! str_starts_with($url, 'https://')) {
                continue;
            }

            if (! $this->looksLikeDirectImageUrl($url)) {
                continue;
            }

            $found[$url] = [
                'type' => 'image',
                'url' => $url,
                'mime' => null,
            ];
        }

        return array_values($found);
    }

    /**
     * True when the payload likely contains media even if no image URL was parsed.
     *
     * @param  array<string, mixed>  $message
     */
    public function looksLikeUnresolvedMedia(array $message): bool
    {
        if (! empty($message['is_unsupported'])) {
            return true;
        }

        if (data_get($message, 'reply_to.story') !== null) {
            return true;
        }

        $attachments = $message['attachments'] ?? null;

        if (is_array($attachments) && $attachments !== []) {
            return true;
        }

        $shares = data_get($message, 'shares');

        return is_array($shares) && $shares !== [];
    }

    /**
     * @return list<array{type: string, url: string, mime: string|null}>
     */
    public function lookupImageAttachments(string $messageId, string $accessToken): array
    {
        $messageId = trim($messageId);
        if ($messageId === '' || str_starts_with($messageId, 'fallback-') || $accessToken === '') {
            return [];
        }

        $base = rtrim((string) config('services.meta.instagram_graph_base_url', 'https://graph.instagram.com'), '/');
        $version = trim((string) config('services.meta.graph_api_version', 'v25.0'), '/');

        $response = Http::timeout(20)
            ->acceptJson()
            ->withToken($accessToken)
            ->get($base.'/'.$version.'/'.$messageId, [
                'fields' => 'id,message,attachments,shares',
            ]);

        if (! $response->successful()) {
            Log::warning('Instagram media lookup failed.', [
                'message_id' => $messageId,
                'status' => $response->status(),
            ]);

            return [];
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            return [];
        }

        $attachments = $this->extractImageAttachments($payload);

        if ($attachments === []) {
            Log::info('Instagram media lookup returned no image URLs.', [
                'message_id' => $messageId,
                'attachment_keys' => $this->debugAttachmentKeys($payload),
            ]);
        }

        return $attachments;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function attachmentRows(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        if (isset($raw['data']) && is_array($raw['data'])) {
            $raw = $raw['data'];
        }

        $rows = [];
        foreach ($raw as $item) {
            if (is_array($item)) {
                $rows[] = $item;
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $attachment
     */
    private function imageUrlFromAttachment(array $attachment): string
    {
        $type = strtolower(trim((string) ($attachment['type'] ?? '')));
        if (in_array($type, ['video', 'audio', 'file', 'reel', 'ig_reel'], true)) {
            return '';
        }

        $candidates = [
            data_get($attachment, 'payload.url'),
            data_get($attachment, 'payload.image_data.url'),
            data_get($attachment, 'payload.image_data.preview_url'),
            data_get($attachment, 'image_data.url'),
            data_get($attachment, 'image_data.preview_url'),
            $attachment['url'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $url = trim((string) $candidate);
            if ($url !== '' && str_starts_with($url, 'https://')) {
                return $url;
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $attachment
     */
    private function mimeFromAttachment(array $attachment): ?string
    {
        $mime = trim((string) (
            data_get($attachment, 'payload.mime_type')
            ?: data_get($attachment, 'mime_type')
            ?: ''
        ));

        return $mime !== '' ? $mime : null;
    }

    private function looksLikeDirectImageUrl(string $url): bool
    {
        $path = strtolower((string) (parse_url($url, PHP_URL_PATH) ?? ''));

        return (bool) preg_match('/\.(jpe?g|png|gif|webp)(\?|$)/', $path);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function debugAttachmentKeys(array $payload): array
    {
        $keys = [];
        foreach ($this->attachmentRows($payload['attachments'] ?? null) as $index => $attachment) {
            $keys[] = $index.':'.implode(',', array_keys($attachment));
        }

        return $keys;
    }
}
