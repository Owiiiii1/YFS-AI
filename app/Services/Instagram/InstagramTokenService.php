<?php

namespace App\Services\Instagram;

use App\Models\InstagramAccount;
use App\Services\Meta\MetaAppSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class InstagramTokenService
{
    public function __construct(
        private readonly MetaAppSettings $metaAppSettings,
    ) {}

    public function exchangeShortLivedForLongLived(InstagramAccount $account, string $shortLivedToken): array
    {
        $clientSecret = (string) ($this->metaAppSettings->instagramAppSecret() ?? '');
        if ($shortLivedToken === '' || $clientSecret === '') {
            throw new \RuntimeException('Token exchange prerequisites are missing.');
        }

        $params = [
            'grant_type' => 'ig_exchange_token',
            'client_secret' => $clientSecret,
            'access_token' => $shortLivedToken,
        ];
        $method = $this->exchangeMethod();
        $endpoint = $this->exchangeEndpoint();
        $request = Http::timeout(20)->acceptJson();

        $response = $method === 'POST'
            ? $request->asForm()->post($endpoint, $params)
            : $request->get($endpoint, $params);

        if (! $response->successful()) {
            $error = $this->safeErrorMessage((array) $response->json(), 'Instagram short-lived token exchange failed.');
            $this->markExchangeFailedDiagnostics($account, $error);

            throw new \RuntimeException($error);
        }

        $payload = (array) $response->json();
        $longLivedToken = (string) ($payload['access_token'] ?? '');
        if ($longLivedToken === '') {
            $error = 'Instagram short-lived token exchange did not return access token.';
            $this->markExchangeFailedDiagnostics($account, $error);

            throw new \RuntimeException($error);
        }

        $expiresIn = (int) ($payload['expires_in'] ?? 0);
        $account->forceFill([
            'access_token_encrypted' => $longLivedToken,
            'token_type' => 'long_lived',
            'token_expires_at' => $expiresIn > 0 ? now()->addSeconds($expiresIn) : null,
            'token_refreshed_at' => now(),
            'token_last_checked_at' => now(),
            'token_refresh_error' => null,
            'token_refresh_failed_at' => null,
        ])->save();

        return [
            'token_type' => (string) ($payload['token_type'] ?? 'bearer'),
            'expires_in' => $expiresIn,
            'token_expires_at' => $account->token_expires_at?->toDateTimeString(),
            'exchange_method' => $method,
            'exchange_endpoint' => $endpoint,
        ];
    }

    public function refreshLongLivedToken(InstagramAccount $account): bool
    {
        $account->token_last_checked_at = now();
        $account->save();

        $token = (string) ($account->access_token_encrypted ?? '');
        if ($token === '') {
            $this->markRefreshFailed($account, 'No token to refresh.');

            return false;
        }

        if ($account->token_refreshed_at && $account->token_refreshed_at->gt(now()->subHours(24))) {
            return false;
        }

        $response = Http::timeout(20)
            ->acceptJson()
            ->get($this->refreshEndpoint(), [
                'grant_type' => 'ig_refresh_token',
                'access_token' => $token,
            ]);

        if (! $response->successful()) {
            $this->markRefreshFailed($account, $this->safeErrorMessage((array) $response->json(), 'Instagram token refresh failed.'));

            return false;
        }

        $payload = (array) $response->json();
        $newToken = (string) ($payload['access_token'] ?? '');
        if ($newToken === '') {
            $this->markRefreshFailed($account, 'Refresh response does not include access token.');

            return false;
        }

        $expiresIn = (int) ($payload['expires_in'] ?? 0);
        $account->forceFill([
            'access_token_encrypted' => $newToken,
            'token_type' => 'long_lived',
            'token_expires_at' => $expiresIn > 0 ? now()->addSeconds($expiresIn) : null,
            'token_refreshed_at' => now(),
            'token_last_checked_at' => now(),
            'token_refresh_error' => null,
            'token_refresh_failed_at' => null,
        ])->save();

        return true;
    }

    public function ensureFreshToken(InstagramAccount $account): InstagramAccount
    {
        if ($this->shouldRefresh($account)) {
            $this->refreshLongLivedToken($account);
        }

        return $account->fresh() ?? $account;
    }

    public function shouldRefresh(InstagramAccount $account): bool
    {
        if (blank($account->access_token_encrypted)) {
            return false;
        }

        if (! $account->token_expires_at) {
            return true;
        }

        $days = max(1, (int) config('services.meta.token_refresh_days_before_expiry', 14));

        return $account->token_expires_at->lte(now()->addDays($days));
    }

    public function markRefreshFailed(InstagramAccount $account, Throwable|string $error): void
    {
        $message = $error instanceof Throwable ? $error->getMessage() : (string) $error;
        $safe = Str::limit($this->sanitizeMessage($message), 220, '...');

        $account->forceFill([
            'token_refresh_failed_at' => now(),
            'token_refresh_error' => $safe,
            'token_last_checked_at' => now(),
        ])->save();
    }

    public function safeTokenStatus(InstagramAccount $account): array
    {
        $expiresAt = $account->token_expires_at;
        $hasToken = filled($account->access_token_encrypted);
        $daysUntilExpiry = $expiresAt ? now()->diffInDays($expiresAt, false) : null;

        $status = 'ok';
        if (! $hasToken) {
            $status = 'missing';
        } elseif ($expiresAt && $expiresAt->isPast()) {
            $status = 'expired';
        } elseif ($account->token_refresh_failed_at && filled($account->token_refresh_error)) {
            $status = 'failed';
        } elseif ($this->shouldRefresh($account)) {
            $status = 'refresh_due';
        }

        return [
            'token_type' => $account->token_type ?: 'unknown',
            'has_token' => $hasToken,
            'token_expires_at' => $expiresAt?->toDateTimeString(),
            'token_refreshed_at' => $account->token_refreshed_at?->toDateTimeString(),
            'token_last_checked_at' => $account->token_last_checked_at?->toDateTimeString(),
            'token_refresh_failed_at' => $account->token_refresh_failed_at?->toDateTimeString(),
            'days_until_expiry' => $daysUntilExpiry,
            'status' => $status,
            'too_new_to_refresh' => $account->token_refreshed_at?->gt(now()->subHours(24)) ?? false,
        ];
    }

    private function safeErrorMessage(array $payload, string $fallback): string
    {
        $error = data_get($payload, 'error');
        if (! is_array($error)) {
            return Str::limit($this->sanitizeMessage($fallback), 220, '...');
        }

        $message = (string) data_get($error, 'message', $fallback);
        $type = (string) data_get($error, 'type', '');
        $code = data_get($error, 'code');
        $subcode = data_get($error, 'error_subcode');
        $fbtrace = (string) data_get($error, 'fbtrace_id', '');

        $parts = [$this->sanitizeMessage($message)];
        if ($type !== '') {
            $parts[] = 'type='.$type;
        }
        if ($code !== null) {
            $parts[] = 'code='.(string) $code;
        }
        if ($subcode !== null) {
            $parts[] = 'subcode='.(string) $subcode;
        }
        if ($fbtrace !== '') {
            $parts[] = 'fbtrace_id='.$fbtrace;
        }

        $composed = implode('; ', $parts);
        if (str_contains(strtolower($message), 'unsupported request - method type: get')) {
            $composed .= '; hint=Run php artisan instagram:tokens:exchange-probe immediately after OAuth callback.';
        }

        return Str::limit($composed, 220, '...');
    }

    private function sanitizeMessage(string $message): string
    {
        return preg_replace('/(access_token|client_secret|code)=([^&\s]+)/i', '$1=***', $message) ?: 'Operation failed.';
    }

    private function markExchangeFailedDiagnostics(InstagramAccount $account, string $error): void
    {
        $account->forceFill([
            'token_last_checked_at' => now(),
            'token_refresh_failed_at' => now(),
            'token_refresh_error' => Str::limit($this->sanitizeMessage($error), 220, '...'),
        ])->save();
    }

    public function exchangeMethod(): string
    {
        $method = strtoupper(trim((string) config('services.meta.token_exchange_method', 'GET')));

        return in_array($method, ['GET', 'POST'], true) ? $method : 'GET';
    }

    public function exchangeEndpoint(): string
    {
        $url = trim((string) config('services.meta.token_exchange_url', 'https://graph.instagram.com/access_token'));
        $url = $url !== '' ? $url : 'https://graph.instagram.com/access_token';
        $useVersion = (bool) config('services.meta.token_exchange_use_version', false);
        if (! $useVersion) {
            return $url;
        }

        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (! str_contains($host, 'graph.instagram.com')) {
            return $url;
        }

        $scheme = (string) ($parts['scheme'] ?? 'https');
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $version = trim((string) config('services.meta.graph_api_version', 'v25.0'), '/');
        $path = '/'.ltrim((string) ($parts['path'] ?? '/access_token'), '/');
        $path = preg_replace('#^/v\d+\.\d+/#', '/', $path) ?: $path;
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '';

        return $scheme.'://'.$host.$port.'/'.$version.$path.$query;
    }

    private function refreshEndpoint(): string
    {
        return trim((string) config('services.meta.token_refresh_url', 'https://graph.instagram.com/refresh_access_token'))
            ?: 'https://graph.instagram.com/refresh_access_token';
    }
}

