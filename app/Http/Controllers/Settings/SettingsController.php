<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AiProviderSetting;
use App\Models\InstagramAccount;
use App\Models\TelegramSetting;
use App\Models\User;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\AiConnectionResolver;
use App\Services\Instagram\InstagramTokenService;
use App\Services\Meta\MetaAppSettings;
use App\Services\Meta\MetaInstagramMessageSender;
use App\Services\ElevenLabs\ElevenLabsSettingsService;
use App\Services\Telegram\TelegramBotService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function __construct(
        private readonly AiProviderManager $providerManager,
        private readonly AiConnectionResolver $aiConnectionResolver,
        private readonly InstagramTokenService $instagramTokenService,
        private readonly MetaAppSettings $metaAppSettings,
        private readonly TelegramBotService $telegramBotService,
        private readonly ElevenLabsSettingsService $elevenLabsSettings,
    ) {}

    public function index(Request $request): Response|RedirectResponse
    {
        if ($request->query('tab') === 'facebook') {
            return redirect()->route('settings.index', ['tab' => 'instagram']);
        }

        $tab = $request->query('tab');
        $allowedTabs = ['ai', 'users', 'instagram', 'telegram', 'elevenlabs'];
        if (is_string($tab) && in_array($tab, $allowedTabs, true)) {
            $request->session()->put('settings_tab', $tab);
        } else {
            $tab = $request->session()->get('settings_tab', 'users');
            if (! in_array($tab, $allowedTabs, true)) {
                $tab = 'users';
            }
        }

        $users = User::query()
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'created_at'])
            ->map(static fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'created_at' => optional($user->created_at)->toIso8601String(),
            ])
            ->all();

        $account = InstagramAccount::primary();
        $overview = $this->buildInstagramOverview($account);

        return Inertia::render('Settings/Index', [
            'tab' => $tab,
            'users' => $users,
            'providers' => $this->loadProviders(),
            'roleConnections' => $this->roleConnectionsForFrontend(),
            'overview' => $this->formatOverviewForFrontend($overview),
            'labels' => [
                'instagram' => __('client.instagram'),
                'statuses' => __('client.statuses'),
            ],
            'metaSettings' => $this->metaAppSettings->forFrontend(),
            'telegram' => $this->telegramOverview(),
            'elevenlabs' => $this->elevenLabsSettings->forFrontend(),
        ]);
    }

    public function updateLanguage(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'locale' => ['required', 'in:en,ru,uk'],
        ]);

        $locale = $validated['locale'];

        $request->session()->put('locale', $locale);

        if ($request->user()) {
            $request->user()->forceFill(['locale' => $locale])->save();
        }

        return back();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadProviders(): array
    {
        foreach ($this->providerManager->providers() as $provider) {
            AiProviderSetting::query()->firstOrCreate(
                ['provider' => $provider['provider']],
                ['label' => $provider['label']]
            );
        }

        return AiProviderSetting::query()
            ->whereIn('provider', AiProviderSetting::LLM_PROVIDERS)
            ->orderByRaw("CASE provider WHEN 'openai' THEN 1 WHEN 'anthropic' THEN 2 WHEN 'gemini' THEN 3 ELSE 99 END")
            ->get()
            ->map(function (AiProviderSetting $setting): array {
                $models = collect($setting->available_models ?? [])
                    ->filter(fn (array $model): bool => ! empty($model['id']))
                    ->values()
                    ->all();

                return [
                    'provider' => $setting->provider,
                    'label' => $setting->label ?: ucfirst($setting->provider),
                    'has_api_key' => filled($setting->api_key),
                    'api_key_masked' => $this->maskKey($setting->api_key),
                    'is_connected' => (bool) $setting->is_connected,
                    'is_active' => (bool) $setting->is_active,
                    'active_model' => $setting->active_model,
                    'available_models' => $models,
                    'last_checked_at' => optional($setting->last_checked_at)?->toIso8601String(),
                    'last_error' => $setting->last_error,
                ];
            })
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function roleConnectionsForFrontend(): array
    {
        return collect($this->aiConnectionResolver->roleConnections())
            ->map(fn ($connection): array => [
                'role' => $connection->role,
                'provider' => $connection->provider,
                'model' => $connection->active_model,
                'is_connected' => (bool) $connection->is_connected,
                'last_checked_at' => optional($connection->last_checked_at)?->toIso8601String(),
                'last_error' => $connection->last_error,
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildInstagramOverview(InstagramAccount $account): array
    {
        $settings = is_array($account->settings) ? $account->settings : [];
        $tokenStatus = $this->instagramTokenService->safeTokenStatus($account);
        $flow = (string) data_get($settings, 'oauth_flow', config('services.meta.oauth_flow', 'instagram_login'));

        $outboundWarning = null;
        if ($flow === 'instagram_login' && ! app(MetaInstagramMessageSender::class)->canSend($account)) {
            $outboundWarning = __('client.instagram.outbound_warn_instagram_endpoint_missing');
        }

        return [
            'status' => $account->connection_status ?: InstagramAccount::STATUS_NOT_CONFIGURED,
            'instagram_username' => data_get($settings, 'instagram_username'),
            'facebook_page_name' => data_get($settings, 'facebook_page_name'),
            'facebook_page_id' => $account->facebook_page_id,
            'last_success' => $account->last_connection_success_at,
            'last_error' => $account->last_connection_error,
            'token_status' => (string) ($tokenStatus['status'] ?? 'unknown'),
            'token_expires_at' => $account->token_expires_at,
            'token_refreshed_at' => $account->token_refreshed_at,
            'oauth_flow' => $flow,
            'outbound_warning' => $outboundWarning,
        ];
    }

    /**
     * @param  array<string, mixed>  $overview
     * @return array<string, mixed>
     */
    private function formatOverviewForFrontend(array $overview): array
    {
        foreach (['last_success', 'token_expires_at', 'token_refreshed_at'] as $key) {
            $value = $overview[$key] ?? null;
            if ($value instanceof Carbon) {
                $overview[$key] = $value->format('Y-m-d H:i');
            } elseif (is_object($value) && method_exists($value, 'format')) {
                $overview[$key] = $value->format('Y-m-d H:i');
            } else {
                $overview[$key] = $value;
            }
        }

        return $overview;
    }

    /**
     * @return array<string, mixed>
     */
    private function telegramOverview(): array
    {
        $empty = [
            'bot_status' => TelegramSetting::STATUS_NOT_CONFIGURED,
            'bot_username' => null,
            'bot_name' => null,
            'has_bot_token' => false,
            'webhook_url' => $this->telegramBotService->webhookUrl(),
            'channel_status' => TelegramSetting::STATUS_NOT_CONFIGURED,
            'channel_username' => null,
            'channel_title' => null,
            'channel_id' => null,
            'channel_thread_id' => null,
            'last_error' => null,
        ];

        try {
            if (! \Illuminate\Support\Facades\Schema::hasTable('telegram_settings')) {
                return $empty;
            }

            $settings = TelegramSetting::current();

            return [
                'bot_status' => $settings->bot_status ?: TelegramSetting::STATUS_NOT_CONFIGURED,
                'bot_username' => $settings->bot_username,
                'bot_name' => $settings->bot_name,
                'has_bot_token' => filled($settings->bot_token_encrypted),
                'webhook_url' => $this->telegramBotService->webhookUrl(),
                'channel_status' => $settings->channel_status ?: TelegramSetting::STATUS_NOT_CONFIGURED,
                'channel_username' => $settings->channel_username,
                'channel_title' => $settings->channel_title,
                'channel_id' => $settings->channel_id,
                'channel_thread_id' => $settings->channel_thread_id,
                'last_error' => $settings->last_error,
            ];
        } catch (\Throwable) {
            return $empty;
        }
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
