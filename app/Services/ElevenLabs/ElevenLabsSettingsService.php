<?php

namespace App\Services\ElevenLabs;

use App\Models\AiProviderSetting;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

class ElevenLabsSettingsService
{
    public function __construct(
        private readonly ElevenLabsUserClient $userClient,
    ) {}

    public function current(): AiProviderSetting
    {
        return AiProviderSetting::query()->firstOrCreate(
            ['provider' => AiProviderSetting::PROVIDER_ELEVENLABS],
            ['label' => 'ElevenLabs'],
        );
    }

    /**
     * @return array{
     *     status: string,
     *     has_api_key: bool,
     *     api_key_masked: ?string,
     *     last_checked_at: ?string,
     *     last_error: ?string
     * }
     */
    public function forFrontend(): array
    {
        $setting = $this->current();
        $hasKey = filled($setting->api_key);

        $status = match (true) {
            ! $hasKey => 'not_configured',
            (bool) $setting->is_connected => 'connected',
            default => 'failed',
        };

        return [
            'status' => $status,
            'has_api_key' => $hasKey,
            'api_key_masked' => $this->maskKey($setting->api_key),
            'last_checked_at' => optional($setting->last_checked_at)?->toIso8601String(),
            'last_error' => $setting->last_error,
        ];
    }

    public function saveAndVerify(?string $incomingKey): AiProviderSetting
    {
        $setting = $this->current();
        $key = trim((string) $incomingKey);

        if ($key === '') {
            $key = (string) $setting->api_key;
        } else {
            $setting->api_key = $key;
        }

        if ($key === '') {
            $setting->fill([
                'is_connected' => false,
                'last_error' => null,
                'last_checked_at' => Carbon::now(),
            ])->save();

            return $setting;
        }

        try {
            $this->userClient->verifyApiKey($key);
            $setting->fill([
                'is_connected' => true,
                'last_error' => null,
                'last_checked_at' => Carbon::now(),
            ])->save();
        } catch (Throwable $exception) {
            $setting->fill([
                'is_connected' => false,
                'last_error' => $this->publicError($exception),
                'last_checked_at' => Carbon::now(),
            ])->save();
        }

        return $setting->fresh() ?? $setting;
    }

    public function apiKey(): ?string
    {
        $key = $this->current()->api_key;

        return filled($key) ? (string) $key : null;
    }

    private function publicError(Throwable $exception): string
    {
        if ($exception instanceof RuntimeException) {
            return $exception->getMessage();
        }

        return 'ElevenLabs connection check failed.';
    }

    private function maskKey(?string $value): ?string
    {
        if (! filled($value)) {
            return null;
        }

        $plain = trim((string) $value);
        if (strlen($plain) <= 8) {
            return str_repeat('*', strlen($plain));
        }

        return substr($plain, 0, 4).'...'.substr($plain, -4);
    }
}
