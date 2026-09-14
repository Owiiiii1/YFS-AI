<?php

namespace App\Services\ElevenLabs;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class ElevenLabsUserClient
{
    /**
     * Lightweight auth check. Scoped keys often lack user_read / models_read,
     * so we try Speech Engine first (this project's path), then user, then a
     * minimal voices settings probe.
     */
    public function verifyApiKey(string $apiKey): void
    {
        $key = trim($apiKey, " \t\n\r\0\x0B\"'");
        if ($key === '') {
            throw new RuntimeException('ElevenLabs API key is empty.');
        }

        $checks = [
            'https://api.elevenlabs.io/v1/speech-engine',
            'https://api.elevenlabs.io/v1/user',
            'https://api.elevenlabs.io/v1/voices/settings/default',
        ];

        $lastStatus = null;
        $lastDetail = null;

        foreach ($checks as $url) {
            try {
                $response = Http::timeout(10)
                    ->acceptJson()
                    ->withHeaders(['xi-api-key' => $key])
                    ->get($url);
            } catch (Throwable $exception) {
                throw new RuntimeException('Could not reach ElevenLabs.', 0, $exception);
            }

            if ($response->successful()) {
                return;
            }

            $lastStatus = $response->status();
            $lastDetail = $this->detailStatus($response);

            if ($lastDetail === 'missing_permissions') {
                continue;
            }

            if (in_array($lastStatus, [401, 403], true)) {
                throw new RuntimeException('ElevenLabs rejected the API key.');
            }
        }

        if ($lastDetail === 'missing_permissions') {
            throw new RuntimeException('ElevenLabs API key is valid but missing required permissions.');
        }

        throw new RuntimeException('ElevenLabs connection check failed.');
    }

    private function detailStatus(Response $response): ?string
    {
        $detail = $response->json('detail');
        if (is_array($detail) && isset($detail['status']) && is_string($detail['status'])) {
            return $detail['status'];
        }

        return null;
    }
}
