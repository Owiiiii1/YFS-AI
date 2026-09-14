<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FacebookPageAccount;
use App\Services\Meta\FacebookConnectionService;
use App\Services\Meta\MetaFacebookOAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class FacebookSettingsController extends Controller
{
    public function __construct(
        private readonly MetaFacebookOAuthService $oauthService,
        private readonly FacebookConnectionService $connectionService,
    ) {}

    public function show(): RedirectResponse
    {
        return redirect()->route('settings.index', ['tab' => 'instagram']);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'oauth_redirect_uri' => ['nullable', 'string', 'max:2048'],
        ]);

        $account = FacebookPageAccount::primary();
        $settings = is_array($account->settings) ? $account->settings : [];

        if (array_key_exists('oauth_redirect_uri', $data)) {
            $uri = trim((string) ($data['oauth_redirect_uri'] ?? ''));
            $settings['oauth_redirect_uri'] = $uri !== '' ? $uri : null;
        }

        $account->forceFill(['settings' => $settings])->save();

        return back()->with('facebook_status', __('client.facebook.settings_saved'));
    }

    public function testConnection(): RedirectResponse
    {
        $account = FacebookPageAccount::primary();
        $account->is_active = true;
        $account->save();

        $result = $this->connectionService->testWithDiagnostics($account->fresh(), true);

        try {
            $this->connectionService->ensureWebhookSubscription($account->fresh());
            $message = (string) ($result['message'] ?? __('client.facebook.test_success'));
            $message .= ' Webhook subscription enabled.';
        } catch (RuntimeException $exception) {
            $message = (string) ($result['message'] ?? __('client.facebook.test_success'));
            $message .= ' Webhook subscription failed: '.$exception->getMessage();
        }

        return back()->with('facebook_status', $message);
    }

    public function redirectToMeta(Request $request): RedirectResponse
    {
        try {
            $url = $this->oauthService->buildAuthorizationUrl($request->user());

            $account = FacebookPageAccount::primary();
            if ($account->connection_status === FacebookPageAccount::STATUS_NOT_CONFIGURED) {
                $account->connection_status = FacebookPageAccount::STATUS_OAUTH_READY;
                $account->save();
            }

            return redirect()->away($url);
        } catch (RuntimeException $exception) {
            Log::warning('Facebook OAuth redirect build failed.', [
                'user_id' => $request->user()?->id,
                'message' => $exception->getMessage(),
            ]);

            return redirect()
                ->route('settings.index', ['tab' => 'facebook'])
                ->with('facebook_status', $exception->getMessage());
        }
    }

    public function handleMetaCallback(Request $request): RedirectResponse
    {
        try {
            $this->oauthService->handleCallback($request->query(), $request->user());

            return redirect()
                ->route('settings.index', ['tab' => 'facebook'])
                ->with('facebook_status', __('client.facebook.oauth_connected'));
        } catch (RuntimeException $exception) {
            $account = FacebookPageAccount::primary();
            $this->connectionService->markFailed($account, $exception->getMessage());

            return redirect()
                ->route('settings.index', ['tab' => 'facebook'])
                ->with('facebook_status', $exception->getMessage());
        }
    }

    public function disconnect(): RedirectResponse
    {
        $account = FacebookPageAccount::primary();

        if (! $account->exists || $account->connection_status === FacebookPageAccount::STATUS_NOT_CONFIGURED) {
            return back()->with('facebook_status', __('client.facebook.disconnect_nothing'));
        }

        $this->connectionService->disconnect($account);

        return back()->with('facebook_status', __('client.facebook.disconnected'));
    }
}
