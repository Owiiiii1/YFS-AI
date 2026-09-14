<?php

namespace App\Services\Meta;

use App\Models\FacebookPageAccount;
use App\Models\InstagramAccount;
use App\Models\MetaOauthState;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class MetaFacebookOAuthService
{
    /** @var list<string> */
    private const SCOPES = [
        'pages_show_list',
        'pages_messaging',
        'pages_manage_metadata',
        'pages_read_engagement',
        'business_management',
    ];

    public function __construct(
        private readonly MetaAppSettings $metaAppSettings,
    ) {}

    public function buildAuthorizationUrl(User $user): string
    {
        $state = Str::random(64);
        $redirectUri = $this->oauthRedirectUri();
        $clientId = $this->clientId();

        if (blank($clientId) || blank($redirectUri)) {
            throw new RuntimeException('Facebook OAuth is not configured: missing Meta App ID or redirect URI.');
        }

        MetaOauthState::query()->create([
            'user_id' => $user->id,
            'state' => $state,
            'redirect_url' => route('settings.index', ['tab' => 'facebook']),
            'oauth_redirect_uri' => $redirectUri,
            'expires_at' => now()->addMinutes(20),
        ]);

        $version = trim((string) config('services.meta.graph_api_version', 'v25.0'), '/');
        $params = [
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'response_type' => 'code',
            'scope' => implode(',', self::SCOPES),
        ];

        return 'https://www.facebook.com/'.$version.'/dialog/oauth?'.http_build_query($params);
    }

    public function handleCallback(array $query, User $user): FacebookPageAccount
    {
        ['code' => $code, 'oauth_redirect_uri' => $oauthRedirectUri] = $this->validateCallbackRequest($query, $user);

        $tokenData = $this->exchangeCodeForUserToken($code, $oauthRedirectUri);
        $userAccessToken = (string) ($tokenData['access_token'] ?? '');
        if (blank($userAccessToken)) {
            throw new RuntimeException('Meta did not return a user access token for Facebook Page connect.');
        }

        $longLivedUserToken = $this->exchangeForLongLivedUserToken($userAccessToken) ?: $userAccessToken;
        $pages = (array) data_get($this->getUserPages($longLivedUserToken), 'data', []);
        if ($pages === []) {
            throw new RuntimeException('No Facebook Pages available for the current Meta user.');
        }

        $selectedPage = $this->selectPage($pages);
        $pageId = (string) ($selectedPage['id'] ?? '');
        $pageToken = (string) ($selectedPage['access_token'] ?? '');
        if ($pageId === '' || $pageToken === '') {
            throw new RuntimeException('Selected Facebook Page is missing id or access token.');
        }

        $account = FacebookPageAccount::primary();
        $settings = is_array($account->settings) ? $account->settings : [];
        $settings['oauth_connected'] = true;
        $settings['oauth_provider'] = 'meta';
        $settings['oauth_flow'] = 'facebook_page_login';
        $settings['facebook_page_name'] = $selectedPage['name'] ?? null;
        $settings['connected_by_user_id'] = $user->id;
        $settings['available_pages'] = array_map(static fn (array $page): array => [
            'id' => $page['id'] ?? null,
            'name' => $page['name'] ?? null,
        ], $pages);
        $settings['bot_enabled'] = (bool) data_get($settings, 'bot_enabled', true);

        $account->forceFill([
            'name' => $account->name ?: 'Facebook Page Connection',
            'facebook_page_id' => $pageId,
            'access_token_encrypted' => $pageToken,
            'token_type' => 'page',
            'token_expires_at' => null,
            'token_refreshed_at' => now(),
            'token_last_checked_at' => now(),
            'token_refresh_failed_at' => null,
            'token_refresh_error' => null,
            'connection_status' => FacebookPageAccount::STATUS_CONNECTED,
            'is_active' => true,
            'last_connection_check_at' => now(),
            'last_connection_success_at' => now(),
            'last_connection_error' => null,
            'settings' => $settings,
        ])->save();

        if (! $this->subscribePageToWebhooks($pageId, $pageToken)) {
            Log::warning('Facebook Page webhook subscription failed after OAuth.', [
                'page_id' => $pageId,
            ]);
            $account->forceFill([
                'last_connection_error' => 'Connected, but Page webhook subscription failed. Use Test connection to retry.',
            ])->save();
        } else {
            $settings['webhook_subscribed_at'] = now()->toIso8601String();
            $settings['webhook_subscribed_fields'] = 'messages,messaging_postbacks';
            $account->forceFill(['settings' => $settings])->save();
        }

        return $account->fresh();
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

        if (! $response->successful()) {
            Log::warning('Facebook Page subscribed_apps failed.', [
                'page_id' => $pageId,
                'status' => $response->status(),
                'error' => data_get($response->json(), 'error.message'),
            ]);
        }

        return $response->successful();
    }

    /**
     * @return array<string, mixed>
     */
    public function getMessengerUserProfile(string $pageAccessToken, string $psid): array
    {
        $response = Http::timeout(20)
            ->acceptJson()
            ->get($this->graphEndpoint('/'.$psid), [
                'access_token' => $pageAccessToken,
                'fields' => 'first_name,last_name,name,profile_pic',
            ]);

        if (! $response->successful()) {
            throw new RuntimeException((string) data_get($response->json(), 'error.message', 'Messenger profile lookup failed.'));
        }

        return (array) $response->json();
    }

    /**
     * @return array{code: string, oauth_redirect_uri: string}
     */
    private function validateCallbackRequest(array $query, User $user): array
    {
        $state = (string) ($query['state'] ?? '');
        $stateRow = MetaOauthState::query()
            ->where('state', $state)
            ->where('user_id', $user->id)
            ->whereNull('used_at')
            ->first();

        if (! $stateRow || $stateRow->isExpired()) {
            throw new RuntimeException('Invalid or expired OAuth state.');
        }

        if (filled($query['error'] ?? null)) {
            throw new RuntimeException((string) ($query['error_description'] ?? $query['error'] ?? 'Meta OAuth error.'));
        }

        $code = rtrim((string) ($query['code'] ?? ''), '#_');
        if (blank($code)) {
            throw new RuntimeException('OAuth callback does not contain code.');
        }

        $oauthRedirectUri = filled($stateRow->oauth_redirect_uri)
            ? (string) $stateRow->oauth_redirect_uri
            : $this->oauthRedirectUri();

        $stateRow->markUsed();

        return [
            'code' => $code,
            'oauth_redirect_uri' => $oauthRedirectUri,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function exchangeCodeForUserToken(string $code, string $redirectUri): array
    {
        $clientId = $this->clientId();
        $clientSecret = $this->clientSecret();

        if (blank($clientId) || blank($clientSecret)) {
            throw new RuntimeException('Meta App credentials are missing for Facebook token exchange.');
        }

        $response = Http::timeout(20)
            ->acceptJson()
            ->get($this->graphEndpoint('/oauth/access_token'), [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'redirect_uri' => $redirectUri,
                'code' => $code,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException((string) data_get($response->json(), 'error.message', 'Could not exchange OAuth code.'));
        }

        return (array) $response->json();
    }

    private function exchangeForLongLivedUserToken(string $shortLivedToken): ?string
    {
        $clientId = $this->clientId();
        $clientSecret = $this->clientSecret();
        if (blank($clientId) || blank($clientSecret)) {
            return null;
        }

        $response = Http::timeout(20)
            ->acceptJson()
            ->get($this->graphEndpoint('/oauth/access_token'), [
                'grant_type' => 'fb_exchange_token',
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'fb_exchange_token' => $shortLivedToken,
            ]);

        if (! $response->successful()) {
            return null;
        }

        $token = (string) data_get($response->json(), 'access_token', '');

        return $token !== '' ? $token : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function getUserPages(string $userAccessToken): array
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

    /**
     * @param  list<array<string, mixed>>  $pages
     * @return array<string, mixed>
     */
    private function selectPage(array $pages): array
    {
        $preferredPageId = trim((string) (InstagramAccount::primary()->facebook_page_id ?? ''));
        if ($preferredPageId !== '') {
            foreach ($pages as $page) {
                if ((string) ($page['id'] ?? '') === $preferredPageId) {
                    return $page;
                }
            }
        }

        $fbAccount = FacebookPageAccount::primary();
        $existingPageId = trim((string) ($fbAccount->facebook_page_id ?? ''));
        if ($existingPageId !== '') {
            foreach ($pages as $page) {
                if ((string) ($page['id'] ?? '') === $existingPageId) {
                    return $page;
                }
            }
        }

        return $pages[0];
    }

    public function oauthRedirectUri(): string
    {
        $fromAccount = data_get(FacebookPageAccount::primary()->settings, 'oauth_redirect_uri');
        if (is_string($fromAccount) && trim($fromAccount) !== '') {
            return trim($fromAccount);
        }

        return route('facebook.meta.callback', [], true);
    }

    private function clientId(): ?string
    {
        return $this->metaAppSettings->instagramAppId()
            ?? $this->nullableConfig('services.meta.app_id');
    }

    private function clientSecret(): ?string
    {
        return $this->metaAppSettings->instagramAppSecret()
            ?? $this->nullableConfig('services.meta.app_secret');
    }

    private function graphEndpoint(string $path): string
    {
        $base = rtrim((string) config('services.meta.graph_base_url', 'https://graph.facebook.com'), '/');
        $version = trim((string) config('services.meta.graph_api_version', 'v25.0'), '/');

        return $base.'/'.$version.'/'.ltrim($path, '/');
    }

    private function nullableConfig(string $key): ?string
    {
        $value = config($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
