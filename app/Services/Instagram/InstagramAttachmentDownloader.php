<?php

namespace App\Services\Instagram;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class InstagramAttachmentDownloader
{
    /**
     * @param  array{type?: string, url?: string, mime?: string|null}  $attachment
     * @return array{path: string, mime: string}
     */
    public function download(array $attachment, int $conversationId, string $messageId, string $accessToken): array
    {
        $url = trim((string) ($attachment['url'] ?? ''));
        if ($url === '' || ! $this->isTrustedMetaUrl($url)) {
            throw new RuntimeException('Instagram attachment URL is missing or not trusted.');
        }

        $response = Http::timeout(30)
            ->withHeaders(['User-Agent' => 'Mozilla/5.0'])
            ->get($url);

        if (! $response->successful()) {
            $response = Http::timeout(30)
                ->withToken($accessToken)
                ->get($url);
        }

        if (! $response->successful()) {
            $response = Http::timeout(30)
                ->withQueryParameters(['access_token' => $accessToken])
                ->get($url);
        }

        if (! $response->successful()) {
            throw new RuntimeException('Unable to download Instagram attachment (HTTP '.$response->status().').');
        }

        $mime = strtolower(trim((string) ($response->header('Content-Type') ?: $attachment['mime'] ?? 'image/jpeg')));
        $mime = trim(explode(';', $mime)[0]);
        if (! str_starts_with($mime, 'image/')) {
            throw new RuntimeException('Instagram attachment is not an image.');
        }

        $extension = match ($mime) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => 'jpg',
        };

        $safeMessageId = Str::slug($messageId);
        $path = "conversation-attachments/{$conversationId}/{$safeMessageId}-".Str::random(8).".{$extension}";
        Storage::disk('public')->put($path, $response->body());

        return ['path' => $path, 'mime' => $mime];
    }

    private function isTrustedMetaUrl(string $url): bool
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));

        if (($parts['scheme'] ?? '') !== 'https' || $host === '') {
            return false;
        }

        foreach (['instagram.com', 'cdninstagram.com', 'facebook.com', 'fbcdn.net', 'fbsbx.com'] as $domain) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return true;
            }
        }

        return false;
    }
}
