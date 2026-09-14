<?php

namespace App\Services\Meta;

use App\Models\InstagramAccount;
use App\Services\Instagram\InstagramTokenService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class InstagramConnectionService
{
    public function __construct(
        private readonly InstagramTokenService $tokenService,
    ) {}

    public function test(InstagramAccount $account): bool
    {
        $result = $this->testWithDiagnostics($account, true);

        return (bool) ($result['success'] ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function testWithDiagnostics(InstagramAccount $account, bool $applyState = true): array
    {
        $flow = $this->resolveFlow($account);
        $diagnostics = [
            'flow' => $flow,
            'success' => false,
            'result' => 'failed',
            'message' => 'Connection test failed.',
            'checks_performed' => [],
            'checks_skipped' => [],
            'status_before' => $account->connection_status,
            'status_after' => $account->connection_status,
        ];

        if (! $account->is_active) {
            $diagnostics['checks_performed'][] = 'active status check';
            $message = 'Instagram connection is disabled.';
            if ($applyState) {
                $this->disconnect($account);
                $account->forceFill([
                    'last_connection_error' => $message,
                ])->save();
            }
            $diagnostics['message'] = $message;
            $diagnostics['status_after'] = $account->fresh()?->connection_status ?? $account->connection_status;

            return $diagnostics;
        }

        if (! in_array($flow, ['instagram_login', 'facebook_business_login', 'manual_page_token'], true)) {
            $message = 'Unsupported OAuth flow for connection test.';
            if ($applyState) {
                $this->markFailed($account, $message);
            }
            $diagnostics['checks_performed'][] = 'oauth flow check';
            $diagnostics['message'] = $message;
            $diagnostics['status_after'] = $account->fresh()?->connection_status ?? $account->connection_status;

            return $diagnostics;
        }

        if ($flow === 'instagram_login') {
            $diagnostics['checks_skipped'][] = 'webhook_verify_token requirement (irrelevant for instagram_login)';
            $diagnostics['checks_skipped'][] = 'facebook_page_id requirement (irrelevant for instagram_login)';
            $diagnostics['checks_skipped'][] = 'manual page_access_token requirement (irrelevant for instagram_login)';

            return $this->testInstagramLoginFlow($account, $diagnostics, $applyState);
        }

        return $this->testPageBasedFlow($account, $diagnostics, $applyState);
    }

    /**
     * @param  array<string, mixed>  $diagnostics
     * @return array<string, mixed>
     */
    private function testInstagramLoginFlow(InstagramAccount $account, array $diagnostics, bool $applyState): array
    {
        $diagnostics['checks_performed'][] = 'instagram account exists';

        if (($account->connection_status ?? null) === InstagramAccount::STATUS_DISCONNECTED) {
            $message = 'Instagram connection is disconnected. Please reconnect.';
            if ($applyState) {
                $account->forceFill([
                    'last_connection_check_at' => now(),
                    'last_connection_error' => $message,
                    'connection_status' => InstagramAccount::STATUS_NEEDS_RECONNECT,
                ])->save();
            }

            $diagnostics['message'] = $message;
            $diagnostics['result'] = 'needs_reconnect';
            $diagnostics['status_after'] = $account->fresh()?->connection_status ?? $account->connection_status;

            return $diagnostics;
        }

        $diagnostics['checks_performed'][] = 'access token presence';
        if (blank($account->access_token_encrypted)) {
            $message = 'Access token is missing.';
            if ($applyState) {
                $account->forceFill([
                    'connection_status' => InstagramAccount::STATUS_NOT_CONFIGURED,
                    'last_connection_check_at' => now(),
                    'last_connection_error' => $message,
                ])->save();
            }

            $diagnostics['message'] = $message;
            $diagnostics['result'] = 'not_configured';
            $diagnostics['status_after'] = $account->fresh()?->connection_status ?? $account->connection_status;

            return $diagnostics;
        }

        $diagnostics['checks_performed'][] = 'instagram_user_id presence';
        if (blank($account->instagram_user_id)) {
            $message = 'Instagram user ID is missing.';
            if ($applyState) {
                $account->forceFill([
                    'connection_status' => InstagramAccount::STATUS_NOT_CONFIGURED,
                    'last_connection_check_at' => now(),
                    'last_connection_error' => $message,
                ])->save();
            }

            $diagnostics['message'] = $message;
            $diagnostics['result'] = 'not_configured';
            $diagnostics['status_after'] = $account->fresh()?->connection_status ?? $account->connection_status;

            return $diagnostics;
        }

        $diagnostics['checks_performed'][] = 'token expiry check';
        if ($this->tokenService->shouldRefresh($account)) {
            $diagnostics['checks_performed'][] = 'refresh long-lived token (proactive)';
            $refreshed = $this->tokenService->refreshLongLivedToken($account);
            if (! $refreshed && $account->token_expires_at?->isPast()) {
                $message = 'Instagram token is expired and refresh failed. Please reconnect Instagram.';
                if ($applyState) {
                    $account->forceFill([
                        'connection_status' => InstagramAccount::STATUS_NEEDS_RECONNECT,
                        'last_connection_check_at' => now(),
                        'last_connection_error' => $message,
                    ])->save();
                }
                $diagnostics['message'] = $message;
                $diagnostics['result'] = 'needs_reconnect';
                $diagnostics['status_after'] = $account->fresh()?->connection_status ?? $account->connection_status;

                return $diagnostics;
            }
            $account->refresh();
        }

        $diagnostics['checks_performed'][] = 'token type compatibility';
        $tokenType = Str::lower(trim((string) ($account->token_type ?? '')));
        $tokenTypeAllowed = in_array($tokenType, ['', 'unknown', 'active', 'long_lived'], true);
        if (! $tokenTypeAllowed && ! $account->token_expires_at) {
            $message = 'Token type is not compatible with instagram_login flow.';
            if ($applyState) {
                $account->forceFill([
                    'connection_status' => InstagramAccount::STATUS_NEEDS_RECONNECT,
                    'last_connection_check_at' => now(),
                    'last_connection_error' => $message,
                ])->save();
            }
            $diagnostics['message'] = $message;
            $diagnostics['result'] = 'needs_reconnect';
            $diagnostics['status_after'] = $account->fresh()?->connection_status ?? $account->connection_status;

            return $diagnostics;
        }

        $diagnostics['checks_performed'][] = 'instagram graph profile check (/me)';

        try {
            $endpoint = rtrim((string) config('services.meta.instagram_graph_base_url', 'https://graph.instagram.com'), '/').'/me';
            $response = Http::timeout(15)
                ->acceptJson()
                ->get($endpoint, [
                    'access_token' => (string) $account->access_token_encrypted,
                    'fields' => 'id,username,account_type',
                ]);

            if ($response->successful()) {
                if ($applyState) {
                    $this->markConnected($account);
                }

                $diagnostics['success'] = true;
                $diagnostics['result'] = 'connected';
                $diagnostics['message'] = 'Connection test passed.';
                $diagnostics['status_after'] = $account->fresh()?->connection_status ?? $account->connection_status;

                return $diagnostics;
            }

            $payload = (array) $response->json();
            $error = $this->safeGraphError($payload, 'Instagram Graph profile check failed with HTTP '.$response->status());
            if ($this->isTokenError($payload)) {
                $message = 'Instagram token is invalid or expired. Please reconnect Instagram.';
                if ($applyState) {
                    $account->forceFill([
                        'connection_status' => InstagramAccount::STATUS_NEEDS_RECONNECT,
                        'last_connection_check_at' => now(),
                        'last_connection_error' => $message,
                        'token_last_checked_at' => now(),
                    ])->save();
                }
                $diagnostics['message'] = $message;
                $diagnostics['result'] = 'needs_reconnect';
                $diagnostics['status_after'] = $account->fresh()?->connection_status ?? $account->connection_status;

                return $diagnostics;
            }

            // Endpoint/permission-limited errors should not break working token state.
            if ($applyState) {
                $this->markNonCriticalCheckFailure($account, $error);
            }

            $diagnostics['message'] = $error;
            $diagnostics['result'] = 'warning';
            $diagnostics['status_after'] = $account->fresh()?->connection_status ?? $account->connection_status;

            return $diagnostics;
        } catch (Throwable $exception) {
            $error = $this->safeMessage($exception->getMessage(), 'Connection check failed.');
            if ($applyState) {
                $this->markNonCriticalCheckFailure($account, $error);
            }
            $diagnostics['message'] = $error;
            $diagnostics['result'] = 'warning';
            $diagnostics['status_after'] = $account->fresh()?->connection_status ?? $account->connection_status;

            return $diagnostics;
        }
    }

    /**
     * @param  array<string, mixed>  $diagnostics
     * @return array<string, mixed>
     */
    private function testPageBasedFlow(InstagramAccount $account, array $diagnostics, bool $applyState): array
    {
        $flow = (string) $diagnostics['flow'];
        $requiresPageBinding = in_array($flow, ['facebook_business_login', 'manual_page_token'], true);
        $missing = [];

        $diagnostics['checks_performed'][] = 'access token presence';
        if (blank($account->access_token_encrypted)) {
            $missing[] = 'access token';
        }
        $diagnostics['checks_performed'][] = 'instagram_user_id presence';
        if (blank($account->instagram_user_id)) {
            $missing[] = 'instagram_user_id';
        }
        $diagnostics['checks_performed'][] = 'facebook_page_id presence';
        if ($requiresPageBinding && blank($account->facebook_page_id)) {
            $missing[] = 'facebook_page_id';
        }

        if ($missing !== []) {
            $message = $requiresPageBinding
                ? 'Token, Instagram User ID, or Facebook Page ID is missing.'
                : 'Token or Instagram User ID is missing.';
            if ($applyState) {
                $account->forceFill([
                    'connection_status' => InstagramAccount::STATUS_NOT_CONFIGURED,
                    'last_connection_check_at' => now(),
                    'last_connection_error' => $message,
                ])->save();
            }
            $diagnostics['message'] = $message;
            $diagnostics['result'] = 'not_configured';
            $diagnostics['status_after'] = $account->fresh()?->connection_status ?? $account->connection_status;

            return $diagnostics;
        }

        try {
            $baseUrl = rtrim((string) data_get($account->settings, 'graph_base_url', config('services.meta.graph_base_url', 'https://graph.facebook.com')), '/');
            $version = (string) data_get($account->settings, 'graph_api_version', config('services.meta.graph_api_version', 'v25.0'));
            $endpoint = $baseUrl.'/'.$version.'/me';

            $diagnostics['checks_performed'][] = 'facebook graph profile check (/me)';
            $response = Http::timeout(15)
                ->acceptJson()
                ->get($endpoint, [
                    'access_token' => (string) $account->access_token_encrypted,
                    'fields' => 'id,name',
                ]);

            if ($response->successful()) {
                if ($applyState) {
                    $this->markConnected($account);
                }
                $diagnostics['success'] = true;
                $diagnostics['result'] = 'connected';
                $diagnostics['message'] = 'Connection test passed.';
                $diagnostics['status_after'] = $account->fresh()?->connection_status ?? $account->connection_status;

                return $diagnostics;
            }

            $error = $this->safeGraphError((array) $response->json(), 'Graph API request failed with HTTP '.$response->status());
            if ($applyState) {
                $this->markFailed($account, $error);
            }
            $diagnostics['message'] = $error;
            $diagnostics['result'] = 'failed';
            $diagnostics['status_after'] = $account->fresh()?->connection_status ?? $account->connection_status;

            return $diagnostics;
        } catch (Throwable $exception) {
            $error = $this->safeMessage($exception->getMessage(), 'Connection check failed.');
            if ($applyState) {
                $this->markFailed($account, $error);
            }
            $diagnostics['message'] = $error;
            $diagnostics['result'] = 'failed';
            $diagnostics['status_after'] = $account->fresh()?->connection_status ?? $account->connection_status;

            return $diagnostics;
        }
    }

    private function resolveFlow(InstagramAccount $account): string
    {
        $flow = (string) data_get($account->settings, 'oauth_flow', config('services.meta.oauth_flow', 'instagram_login'));
        if ((bool) data_get($account->settings, 'manual_page_token', false)) {
            return 'manual_page_token';
        }

        return in_array($flow, ['instagram_login', 'facebook_business_login', 'manual_page_token'], true)
            ? $flow
            : 'instagram_login';
    }

    private function markNonCriticalCheckFailure(InstagramAccount $account, string $error): void
    {
        $safeError = $this->safeMessage($error, 'Connection check failed.');
        $status = $account->connection_status === InstagramAccount::STATUS_CONNECTED
            ? InstagramAccount::STATUS_CONNECTED
            : InstagramAccount::STATUS_FAILED;

        $account->forceFill([
            'connection_status' => $status,
            'last_connection_check_at' => now(),
            'last_connection_error' => $safeError,
            'token_last_checked_at' => now(),
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function isTokenError(array $payload): bool
    {
        $code = (int) data_get($payload, 'error.code', 0);
        $subcode = (int) data_get($payload, 'error.error_subcode', 0);
        $message = Str::lower((string) data_get($payload, 'error.message', ''));

        if ($code === 190) {
            return true;
        }

        if ($subcode !== 0 && in_array($subcode, [463, 467], true)) {
            return true;
        }

        return str_contains($message, 'access token') && (
            str_contains($message, 'expired')
            || str_contains($message, 'invalid')
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function safeGraphError(array $payload, string $fallback): string
    {
        $message = (string) data_get($payload, 'error.message', $fallback);
        $type = (string) data_get($payload, 'error.type', '');
        $code = data_get($payload, 'error.code');

        $parts = [$this->safeMessage($message, $fallback)];
        if ($type !== '') {
            $parts[] = 'type='.$type;
        }
        if ($code !== null) {
            $parts[] = 'code='.(string) $code;
        }

        return Str::limit(implode('; ', $parts), 220, '...');
    }

    private function safeMessage(string $message, string $fallback): string
    {
        $sanitized = preg_replace('/(access_token|client_secret|code)=([^&\s]+)/i', '$1=***', $message) ?: $fallback;

        return Str::limit(trim($sanitized) ?: $fallback, 220, '...');
    }

    public function isIrrelevantManualFieldError(?string $error): bool
    {
        $value = Str::lower((string) $error);
        if ($value === '') {
            return false;
        }

        return str_contains($value, 'webhook verify token')
            || str_contains($value, 'facebook page id')
            || str_contains($value, 'facebook_page_id')
            || str_contains($value, 'page access token')
            || str_contains($value, 'page_access_token');
    }

    public function disconnect(InstagramAccount $account): void
    {
        $settings = is_array($account->settings) ? $account->settings : [];
        $settings['oauth_connected'] = false;

        $account->forceFill([
            'is_active' => false,
            'connection_status' => InstagramAccount::STATUS_DISCONNECTED,
            'last_connection_check_at' => now(),
            'settings' => $settings,
        ])->save();
    }

    public function markConnected(InstagramAccount $account): void
    {
        $account->forceFill([
            'connection_status' => InstagramAccount::STATUS_CONNECTED,
            'is_active' => true,
            'last_connection_check_at' => now(),
            'last_connection_success_at' => now(),
            'last_connection_error' => null,
            'token_last_checked_at' => now(),
        ])->save();
    }

    public function markFailed(InstagramAccount $account, string $error): void
    {
        $account->forceFill([
            'connection_status' => InstagramAccount::STATUS_FAILED,
            'last_connection_check_at' => now(),
            'last_connection_error' => $error,
        ])->save();
    }

    public function getStatusSummary(): array
    {
        $account = InstagramAccount::query()->orderBy('id')->first();

        if (! $account) {
            return [
                'status' => InstagramAccount::STATUS_NOT_CONFIGURED,
                'is_connected' => false,
                'name' => null,
                'instagram_user_id' => null,
                'last_error' => null,
                'last_check' => null,
                'last_success' => null,
            ];
        }

        return [
            'status' => $account->connection_status ?? InstagramAccount::STATUS_NOT_CONFIGURED,
            'is_connected' => $account->isConnected(),
            'name' => $account->name,
            'instagram_user_id' => $account->instagram_user_id,
            'last_error' => $account->last_connection_error,
            'last_check' => $account->last_connection_check_at,
            'last_success' => $account->last_connection_success_at,
        ];
    }
}
