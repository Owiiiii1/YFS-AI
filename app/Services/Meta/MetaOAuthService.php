<?php

namespace App\Services\Meta;

use App\Models\InstagramAccount;
use App\Models\MetaOauthState;
use App\Models\User;
use App\Services\Instagram\InstagramTokenService;
use App\Services\Meta\MetaAppSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class MetaOAuthService
{
    public function __construct(
        private readonly InstagramTokenService $tokenService,
        private readonly MetaAppSettings $metaAppSettings,
    ) {}

    public function buildAuthorizationUrl(User $user): string
    {
        $state = Str::random(64);

        $redirectUri = $this->metaAppSettings->oauthRedirectUri();
        $authorizeUrl = $this->authorizeUrl();
        $clientId = $this->authorizeClientId();
        $scopes = (array) config('services.meta.oauth_scopes', []);

        if (blank($clientId) || blank($redirectUri)) {
            throw new RuntimeException('Meta OAuth is not configured: missing client app id or META_OAUTH_REDIRECT_URI.');
        }

        MetaOauthState::query()->create([
            'user_id' => $user->id,
            'state' => $state,
            'redirect_url' => route('instagram.index'),
            'oauth_redirect_uri' => $redirectUri,
            'expires_at' => now()->addMinutes(20),
        ]);

        $params = [
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'state' => $state,
        ];

        if ($scopes !== []) {
            $params['scope'] = implode(',', $scopes);
        }

        if ($this->shouldSendConfigId()) {
            $params['config_id'] = (string) config('services.meta.oauth_config_id');
        }

        return $authorizeUrl.'?'.http_build_query($params);
    }

    public function handleCallback(array $query, User $user): InstagramAccount
    {
        if ($this->oauthFlow() === 'instagram_login') {
            return $this->handleInstagramLoginCallback($query, $user);
        }

        if ($this->oauthFlow() === 'facebook_business_login') {
            return $this->handleFacebookBusinessLoginCallback($query, $user);
        }

        throw new RuntimeException('Unsupported OAuth flow.');
    }

    private function handleInstagramLoginCallback(array $query, User $user): InstagramAccount
    {
        ['code' => $code, 'oauth_redirect_uri' => $oauthRedirectUri] = $this->validateCallbackRequest($query, $user);
        $tokenData = $this->normalizeTokenExchangeResponse($this->exchangeCodeForAccessToken($code, $oauthRedirectUri));
        $accessToken = (string) ($tokenData['access_token'] ?? '');
        $instagramUserId = isset($tokenData['user_id']) ? (string) $tokenData['user_id'] : null;
        $tokenExpiresIn = isset($tokenData['expires_in']) ? (int) $tokenData['expires_in'] : null;

        if (blank($accessToken)) {
            throw new RuntimeException('Meta did not return Instagram access token.');
        }

        $account = InstagramAccount::primary();

        $settings = is_array($account->settings) ? $account->settings : [];
        $settings['oauth_connected'] = true;
        $settings['oauth_provider'] = 'meta';
        $settings['oauth_flow'] = 'instagram_login';
        $settings['connected_by_user_id'] = $user->id;
        $settings['token_exchange_failed'] = false;
        $settings['token_exchange_error'] = null;

        try {
            $this->tokenService->exchangeShortLivedForLongLived($account, $accessToken);
        } catch (RuntimeException $exception) {
            $errorMessage = Str::limit($this->normalizeOAuthError([
                'error' => ['message' => $exception->getMessage()],
            ], 'Instagram token exchange failed.'), 220, '...');
            $settings['oauth_connected'] = false;
            $settings['token_exchange_failed'] = true;
            $settings['token_exchange_error'] = $errorMessage;
            $settings['oauth_flow'] = 'instagram_login';

            $account->forceFill([
                'access_token_encrypted' => $accessToken,
                'token_type' => 'short_lived',
                'token_expires_at' => $tokenExpiresIn ? now()->addSeconds($tokenExpiresIn) : null,
                'connection_status' => InstagramAccount::STATUS_FAILED,
                'is_active' => true,
                'last_connection_check_at' => now(),
                'last_connection_error' => $errorMessage,
                'settings' => $settings,
            ])->save();

            $this->safeLogWarning('Instagram short-lived token exchange failed.', [
                'user_id' => $user->id,
                'flow' => 'instagram_login',
                'error' => $errorMessage,
            ]);

            throw new RuntimeException('Instagram authorization succeeded, but token activation failed. Please reconnect or contact support.');
        }

        try {
            $profile = $this->getInstagramUserProfile((string) $account->access_token_encrypted, $instagramUserId);
            $profileId = isset($profile['id']) ? (string) $profile['id'] : null;
            $profileUsername = isset($profile['username']) ? (string) $profile['username'] : null;
            $settings['instagram_profile'] = [
                'id' => $profileId,
                'user_id' => isset($profile['user_id']) ? (string) $profile['user_id'] : $instagramUserId,
                'username' => $profileUsername,
                'account_type' => isset($profile['account_type']) ? (string) $profile['account_type'] : null,
            ];
            $settings['instagram_username'] = $profileUsername;

            $account->forceFill([
                'name' => $profileUsername ?: ('Instagram Account '.($profileId ?: ($instagramUserId ?: $account->id))),
                'instagram_user_id' => $profileId ?: $instagramUserId,
                'facebook_page_id' => null,
                'token_type' => 'long_lived',
                'connection_status' => InstagramAccount::STATUS_CONNECTED,
                'is_active' => true,
                'last_connection_check_at' => now(),
                'last_connection_success_at' => now(),
                'last_connection_error' => null,
                'settings' => $settings,
            ])->save();

            $this->safeSubscribeWebhooks($account);
        } catch (RuntimeException $exception) {
            $settings['oauth_connected'] = false;
            $settings['instagram_profile'] = null;
            $errorMessage = Str::limit($exception->getMessage(), 220, '...');

            $account->forceFill([
                'instagram_user_id' => $instagramUserId,
                'facebook_page_id' => null,
                'token_type' => 'long_lived',
                'connection_status' => InstagramAccount::STATUS_FAILED,
                'is_active' => true,
                'last_connection_check_at' => now(),
                'last_connection_error' => $errorMessage,
                'settings' => $settings,
            ])->save();

            $this->safeLogWarning('Instagram OAuth profile lookup failed.', [
                'user_id' => $user->id,
                'flow' => 'instagram_login',
                'endpoint_type' => 'instagram_graph_profile',
                'error' => $errorMessage,
            ]);
        }

        return $account;
    }

    private function handleFacebookBusinessLoginCallback(array $query, User $user): InstagramAccount
    {
        ['code' => $code, 'oauth_redirect_uri' => $oauthRedirectUri] = $this->validateCallbackRequest($query, $user);
        $tokenData = $this->normalizeTokenExchangeResponse($this->exchangeCodeForAccessToken($code, $oauthRedirectUri));
        $userAccessToken = (string) ($tokenData['access_token'] ?? '');
        if (blank($userAccessToken)) {
            throw new RuntimeException('Meta did not return user access token.');
        }

        $pagesResponse = $this->getUserPages($userAccessToken);
        $pages = (array) ($pagesResponse['data'] ?? []);
        if ($pages === []) {
            throw new RuntimeException('No Facebook Pages available for current Meta user.');
        }

        $selectedPage = null;
        $selectedIg = null;
        foreach ($pages as $page) {
            $ig = $this->getInstagramAccountForPage((string) ($page['id'] ?? ''), (string) ($page['access_token'] ?? ''));
            if ($ig !== null) {
                $selectedPage = $page;
                $selectedIg = $ig;
                break;
            }
        }

        if ($selectedPage === null || $selectedIg === null) {
            throw new RuntimeException('No Page with connected Instagram Business Account found.');
        }

        $account = InstagramAccount::primary();

        $settings = is_array($account->settings) ? $account->settings : [];
        $settings['oauth_connected'] = true;
        $settings['oauth_provider'] = 'meta';
        $settings['oauth_flow'] = 'facebook_business_login';
        $settings['facebook_page_name'] = $selectedPage['name'] ?? null;
        $settings['granted_scopes'] = $tokenData['granted_scopes'] ?? [];
        $settings['connected_by_user_id'] = $user->id;
        $settings['available_pages'] = array_map(static function (array $page): array {
            return [
                'id' => $page['id'] ?? null,
                'name' => $page['name'] ?? null,
            ];
        }, $pages);

        $account->forceFill([
            'name' => $account->name ?: 'Instagram Connection',
            'instagram_user_id' => $selectedIg['id'] ?? null,
            'facebook_page_id' => $selectedPage['id'] ?? null,
            'access_token_encrypted' => $selectedPage['access_token'] ?? null,
            'token_type' => 'long_lived',
            'token_refreshed_at' => now(),
            'token_last_checked_at' => now(),
            'token_refresh_failed_at' => null,
            'token_refresh_error' => null,
            'connection_status' => InstagramAccount::STATUS_CONNECTED,
            'is_active' => true,
            'last_connection_check_at' => now(),
            'last_connection_success_at' => now(),
            'last_connection_error' => null,
            'settings' => $settings,
        ])->save();

        $this->subscribePageToWebhooks((string) ($selectedPage['id'] ?? ''), (string) ($selectedPage['access_token'] ?? ''));

        return $account;
    }

    /**
     * @return array{code: string, oauth_redirect_uri: string}
     */
    private function validateCallbackRequest(array $query, User $user): array
    {
        $state = (string) ($query['state'] ?? '');
        $stateRow = $this->resolveValidStateRow($state, $user);
        if ($stateRow === null) {
            throw new RuntimeException('Invalid or expired OAuth state.');
        }

        if (filled($query['error'] ?? null)) {
            $message = (string) ($query['error_description'] ?? $query['error'] ?? 'Meta OAuth error.');
            throw new RuntimeException($message);
        }

        $code = (string) ($query['code'] ?? '');
        $code = rtrim($code, '#_');
        if (blank($code)) {
            throw new RuntimeException('OAuth callback does not contain code.');
        }

        $oauthRedirectUri = filled($stateRow->oauth_redirect_uri)
            ? (string) $stateRow->oauth_redirect_uri
            : $this->metaAppSettings->oauthRedirectUri();

        $stateRow->markUsed();

        return [
            'code' => $code,
            'oauth_redirect_uri' => $oauthRedirectUri,
        ];
    }

    public function exchangeCodeForAccessToken(string $code, ?string $redirectUri = null): array
    {
        $tokenUrl = $this->tokenUrl();
        $clientId = $this->tokenClientId();
        $clientSecret = $this->tokenClientSecret();
        $redirectUri = $redirectUri ?: $this->metaAppSettings->oauthRedirectUri();

        if (blank($clientId) || blank($clientSecret) || blank($redirectUri)) {
            throw new RuntimeException('Meta OAuth token exchange is not configured: missing client id/secret or redirect URI.');
        }

        $payload = [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirectUri,
            'code' => $code,
            'grant_type' => 'authorization_code',
        ];

        $request = Http::timeout(20)->acceptJson();

        $response = $this->oauthFlow() === 'facebook_business_login'
            ? $request->get($tokenUrl, $payload)
            : $request->asForm()->post($tokenUrl, $payload);

        if (! $response->successful()) {
            $errorMessage = $this->normalizeOAuthError($response->json(), 'Could not exchange OAuth code.');

            $this->safeLogWarning('Meta OAuth token exchange failed.', [
                'flow' => $this->oauthFlow(),
                'token_url' => $tokenUrl,
                'status' => $response->status(),
                'error' => $errorMessage,
                'response_body' => Str::limit((string) $response->body(), 500, '...'),
            ]);

            throw new RuntimeException($errorMessage);
        }

        return (array) $response->json();
    }

    public function getUserPages(string $userAccessToken): array
    {
        $response = Http::timeout(20)
            ->acceptJson()
            ->get($this->graphEndpoint('/me/accounts'), [
                'access_token' => $userAccessToken,
                'fields' => 'id,name,access_token',
            ]);

        if (! $response->successful()) {
            throw new RuntimeException((string) data_get($response->json(), 'error.message', 'Could not load Facebook pages.'));
        }

        return (array) $response->json();
    }

    public function getInstagramUserProfile(string $instagramAccessToken, ?string $userId = null): array
    {
        $baseUrl = rtrim((string) config('services.meta.instagram_graph_base_url', 'https://graph.instagram.com'), '/');
        $fieldSets = [
            'id,user_id,username,account_type,name',
            'id,username,account_type,name',
            'id,username,name',
            'id,username,account_type',
            'id,username',
            'id',
        ];

        if (filled($userId)) {
            return $this->requestInstagramProfile($baseUrl.'/'.trim($userId), $instagramAccessToken, $fieldSets);
        }

        return $this->requestInstagramProfile($baseUrl.'/me', $instagramAccessToken, $fieldSets);
    }

    /**
     * @param  list<string>  $fieldSets
     * @return array<string, mixed>
     */
    private function requestInstagramProfile(string $endpoint, string $instagramAccessToken, array $fieldSets): array
    {
        foreach ($fieldSets as $fieldSet) {
            $response = Http::timeout(20)
                ->acceptJson()
                ->get($endpoint, [
                    'access_token' => $instagramAccessToken,
                    'fields' => $fieldSet,
                ]);

            if ($response->successful()) {
                return (array) $response->json();
            }
        }

        throw new RuntimeException('Instagram profile lookup failed. Check Instagram Graph token compatibility.');
    }

    public function getInstagramAccountForPage(string $pageId, string $pageAccessToken): ?array
    {
        if (blank($pageId) || blank($pageAccessToken)) {
            return null;
        }

        $response = Http::timeout(20)
            ->acceptJson()
            ->get($this->graphEndpoint('/'.$pageId), [
                'access_token' => $pageAccessToken,
                'fields' => 'instagram_business_account{id,username}',
            ]);

        if (! $response->successful()) {
            return null;
        }

        $ig = data_get($response->json(), 'instagram_business_account');
        if (! is_array($ig) || blank($ig['id'] ?? null)) {
            return null;
        }

        return $ig;
    }

    public function subscribePageToWebhooks(string $pageId, string $pageAccessToken): bool
    {
        if (blank($pageId) || blank($pageAccessToken)) {
            return false;
        }

        $response = Http::timeout(20)
            ->asForm()
            ->post($this->graphEndpoint('/'.$pageId.'/subscribed_apps'), [
                'access_token' => $pageAccessToken,
                'subscribed_fields' => 'messages,messaging_postbacks',
            ]);

        return $response->successful();
    }

    public function validateState(string $state, User $user): bool
    {
        return $this->resolveValidStateRow($state, $user) !== null;
    }

    private function resolveValidStateRow(string $state, User $user): ?MetaOauthState
    {
        if (blank($state)) {
            return null;
        }

        $stateRow = MetaOauthState::query()
            ->where('state', $state)
            ->where('user_id', $user->id)
            ->whereNull('used_at')
            ->first();

        if (! $stateRow || $stateRow->isExpired()) {
            return null;
        }

        return $stateRow;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeTokenExchangeResponse(array $payload): array
    {
        $first = data_get($payload, 'data.0');

        return is_array($first) ? $first : $payload;
    }

    private function graphEndpoint(string $path): string
    {
        $base = rtrim((string) config('services.meta.graph_base_url', 'https://graph.facebook.com'), '/');
        $version = trim((string) config('services.meta.graph_api_version', 'v25.0'), '/');
        $path = '/'.ltrim($path, '/');

        return $base.'/'.$version.$path;
    }

    private function oauthFlow(): string
    {
        $flow = strtolower((string) config('services.meta.oauth_flow', 'instagram_login'));

        return in_array($flow, ['instagram_login', 'facebook_business_login'], true)
            ? $flow
            : 'instagram_login';
    }

    private function authorizeClientId(): ?string
    {
        if ($this->oauthFlow() === 'facebook_business_login') {
            return $this->metaAppSettings->instagramAppId()
                ?? $this->nullableConfig('services.meta.app_id');
        }

        return $this->metaAppSettings->instagramAppId();
    }

    private function tokenClientId(): ?string
    {
        return $this->authorizeClientId();
    }

    private function tokenClientSecret(): ?string
    {
        return $this->metaAppSettings->instagramAppSecret();
    }

    private function authorizeUrl(): string
    {
        $configured = $this->nullableConfig('services.meta.oauth_authorize_url');

        if ($configured !== null) {
            return rtrim($configured, '/');
        }

        return $this->oauthFlow() === 'facebook_business_login'
            ? 'https://www.facebook.com/dialog/oauth'
            : 'https://www.instagram.com/oauth/authorize';
    }

    private function tokenUrl(): string
    {
        $configured = $this->nullableConfig('services.meta.oauth_token_url');

        if ($configured !== null) {
            if (
                $this->oauthFlow() === 'facebook_business_login'
                && str_contains(strtolower($configured), 'api.instagram.com')
            ) {
                return $this->graphEndpoint('/oauth/access_token');
            }

            return $configured;
        }

        if ($this->oauthFlow() === 'facebook_business_login') {
            return $this->graphEndpoint('/oauth/access_token');
        }

        return 'https://api.instagram.com/oauth/access_token';
    }

    private function shouldSendConfigId(): bool
    {
        return $this->oauthFlow() === 'facebook_business_login'
            && filled((string) config('services.meta.oauth_config_id'));
    }

    private function nullableConfig(string $key): ?string
    {
        $value = trim((string) config($key));

        return $value === '' ? null : $value;
    }

    private function normalizeOAuthError(mixed $payload, string $fallback): string
    {
        $message = (string) (
            data_get($payload, 'error.message')
            ?: data_get($payload, 'error_message')
            ?: data_get($payload, 'error_description')
            ?: data_get($payload, 'error.error_user_msg')
            ?: $fallback
        );
        $message = preg_replace('/(client_secret|access_token|code)=([^&\s]+)/i', '$1=***', $message ?? '') ?: $fallback;

        return Str::limit(trim($message), 220, '...');
    }

    private function safeLogWarning(string $message, array $context = []): void
    {
        try {
            Log::warning($message, $context);
        } catch (Throwable) {
            // Intentionally swallow logging failures to avoid breaking OAuth callback UX.
        }
    }

    private function safeSubscribeWebhooks(InstagramAccount $account): void
    {
        try {
            app(MetaInstagramWebhookSubscriptionService::class)->subscribe($account);
        } catch (Throwable $exception) {
            $this->safeLogWarning('Instagram webhook subscription after OAuth failed.', [
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
