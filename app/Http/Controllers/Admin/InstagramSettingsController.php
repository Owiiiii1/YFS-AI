<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\InstagramAccount;
use App\Services\Instagram\InstagramTokenService;
use App\Services\Meta\InstagramConnectionService;
use App\Services\Meta\MetaAppSettings;
use App\Services\Meta\MetaInstagramWebhookSubscriptionService;
use App\Services\Meta\MetaOAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class InstagramSettingsController extends Controller
{
    public function __construct(
        private readonly MetaOAuthService $metaOAuthService,
        private readonly InstagramConnectionService $instagramConnectionService,
        private readonly InstagramTokenService $instagramTokenService,
        private readonly MetaAppSettings $metaAppSettings,
        private readonly MetaInstagramWebhookSubscriptionService $webhookSubscriptionService,
    ) {}

    public function show(Request $request): RedirectResponse
    {
        return redirect()->route('settings.index', ['tab' => 'instagram']);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $this->validateInstagramPayload($request);
        $account = InstagramAccount::primary();

        $this->fillInstagramAccountFromPayload($account, $data);
        $account->save();

        return back()->with('instagram_status', __('client.instagram.settings_saved'));
    }

    public function testConnection(Request $request): RedirectResponse
    {
        $account = InstagramAccount::primary();
        $account->is_active = true;
        $account->save();

        $result = $this->instagramConnectionService->testWithDiagnostics($account, true);

        try {
            $this->webhookSubscriptionService->subscribe($account->fresh());
            $message = (string) ($result['message'] ?? __('client.instagram.test_success'));
            $message .= ' Webhook subscription enabled.';
        } catch (RuntimeException $exception) {
            $message = (string) ($result['message'] ?? __('client.instagram.test_success'));
            $message .= ' Webhook subscription failed: '.$exception->getMessage();
        }

        return back()->with('instagram_status', $message);
    }

    public function redirectToMeta(Request $request): RedirectResponse
    {
        try {
            $user = $request->user();
            $url = $this->metaOAuthService->buildAuthorizationUrl($user);

            $account = InstagramAccount::primary();
            if ($account->connection_status === InstagramAccount::STATUS_NOT_CONFIGURED) {
                $account->connection_status = InstagramAccount::STATUS_OAUTH_READY;
                $account->save();
            }

            return redirect()->away($url);
        } catch (RuntimeException $exception) {
            Log::warning('Meta OAuth redirect build failed.', [
                'user_id' => $request->user()?->id,
                'message' => $exception->getMessage(),
            ]);

            return redirect()->route('instagram.index')->with('instagram_status', $exception->getMessage());
        }
    }

    public function handleMetaCallback(Request $request): RedirectResponse
    {
        try {
            $account = $this->metaOAuthService->handleCallback($request->query(), $request->user());

            $pages = data_get($account->settings, 'available_pages', []);
            if (is_array($pages) && count($pages) > 1) {
                $account->last_connection_error = __('client.instagram.multi_pages_first_selected');
                $account->save();
            }

            return redirect()->route('instagram.index')->with('instagram_status', __('client.instagram.oauth_connected'));
        } catch (RuntimeException $exception) {
            $account = InstagramAccount::primary();
            $this->instagramConnectionService->markFailed($account, $exception->getMessage());

            return redirect()->route('instagram.index')->with('instagram_status', $exception->getMessage());
        }
    }

    public function disconnect(Request $request): RedirectResponse
    {
        $account = InstagramAccount::primary();

        if (! $account->exists || $account->connection_status === InstagramAccount::STATUS_NOT_CONFIGURED) {
            return back()->with('instagram_status', __('client.instagram.disconnect_nothing'));
        }

        $this->instagramConnectionService->disconnect($account);

        return back()->with('instagram_status', __('client.instagram.disconnected'));
    }

    private function validateInstagramPayload(Request $request): array
    {
        return $request->validate([
            'instagram_app_id' => ['nullable', 'string', 'max:255'],
            'instagram_app_secret' => ['nullable', 'string', 'max:4096'],
            'webhook_verify_token' => ['nullable', 'string', 'max:255'],
            'oauth_redirect_uri' => ['nullable', 'string', 'max:2048'],
        ]);
    }

    private function fillInstagramAccountFromPayload(InstagramAccount $account, array $data): void
    {
        $settings = is_array($account->settings) ? $account->settings : [];

        if (array_key_exists('instagram_app_id', $data)) {
            $appId = trim((string) ($data['instagram_app_id'] ?? ''));
            if ($appId !== '') {
                $settings['instagram_app_id'] = $appId;
            }
        }

        if (array_key_exists('instagram_app_secret', $data) && filled($data['instagram_app_secret'])) {
            $settings['instagram_app_secret_encrypted'] = Crypt::encryptString(trim((string) $data['instagram_app_secret']));
        }

        if (array_key_exists('webhook_verify_token', $data)) {
            $token = trim((string) ($data['webhook_verify_token'] ?? ''));
            $settings['webhook_verify_token'] = $token !== '' ? $token : null;
        }

        if (array_key_exists('oauth_redirect_uri', $data)) {
            $redirectUri = trim((string) ($data['oauth_redirect_uri'] ?? ''));
            $settings['oauth_redirect_uri'] = $redirectUri !== '' ? $redirectUri : null;
        }

        $account->settings = $settings;
        $account->is_active = true;
    }
}
