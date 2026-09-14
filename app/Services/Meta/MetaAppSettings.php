<?php

namespace App\Services\Meta;

use App\Models\InstagramAccount;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

class MetaAppSettings
{
    public function instagramAppId(): ?string
    {
        $fromAccount = $this->settingString('instagram_app_id');

        if ($fromAccount !== null) {
            return $fromAccount;
        }

        return $this->nullableConfig('services.meta.instagram_app_id')
            ?? $this->nullableConfig('services.meta.app_id');
    }

    public function instagramAppSecret(): ?string
    {
        $encrypted = data_get($this->account()->settings, 'instagram_app_secret_encrypted');

        if (filled($encrypted)) {
            try {
                return Crypt::decryptString((string) $encrypted);
            } catch (DecryptException) {
                return null;
            }
        }

        return $this->nullableConfig('services.meta.instagram_app_secret')
            ?? $this->nullableConfig('services.meta.app_secret');
    }

    public function webhookVerifyToken(): ?string
    {
        return $this->settingString('webhook_verify_token')
            ?? $this->nullableConfig('services.meta.webhook_verify_token');
    }

    public function oauthRedirectUri(): string
    {
        $fromAccount = $this->settingString('oauth_redirect_uri');
        if ($fromAccount !== null) {
            return $fromAccount;
        }

        $fromEnv = $this->nullableConfig('services.meta.oauth_redirect_uri');
        if ($fromEnv !== null) {
            return $fromEnv;
        }

        return route('instagram.meta.callback', [], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function forFrontend(): array
    {
        $settings = is_array($this->account()->settings) ? $this->account()->settings : [];
        $appId = (string) ($settings['instagram_app_id'] ?? '');
        $webhookToken = (string) ($settings['webhook_verify_token'] ?? '');
        $hasSecret = filled(data_get($settings, 'instagram_app_secret_encrypted'))
            || filled(config('services.meta.instagram_app_secret'))
            || filled(config('services.meta.app_secret'));

        return [
            'instagram_app_id' => $appId,
            'has_app_secret' => $hasSecret,
            'app_secret_masked' => $this->maskSecret($this->instagramAppSecret()),
            'webhook_verify_token' => $webhookToken,
            'oauth_redirect_uri' => $this->oauthRedirectUri(),
            'webhook_callback_url' => url('/api/webhooks/meta/instagram'),
            'configured' => filled($this->instagramAppId()) && filled($this->instagramAppSecret()),
        ];
    }

    private function account(): InstagramAccount
    {
        return InstagramAccount::primary();
    }

    private function settingString(string $key): ?string
    {
        $value = data_get($this->account()->settings, $key);

        if (! is_string($value) || blank(trim($value))) {
            return null;
        }

        return trim($value);
    }

    private function nullableConfig(string $key): ?string
    {
        $value = config($key);

        if (! is_string($value) || blank(trim($value))) {
            return null;
        }

        return trim($value);
    }

    private function maskSecret(?string $value): ?string
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
