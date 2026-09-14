<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function handle(Request $request, Closure $next)
    {
        app()->setLocale($this->resolveLocale($request));

        return parent::handle($request, $next);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),

            'auth' => [
                'user' => $request->user() ? [
                    'id' => $request->user()->id,
                    'name' => $request->user()->name,
                    'email' => $request->user()->email,
                ] : null,
            ],

            'locale' => $this->resolveLocale($request),

            'flash' => [
                'instagram_status' => fn () => $request->session()->get('instagram_status'),
                'telegram_status' => fn () => $request->session()->get('telegram_status'),
                'elevenlabs_status' => fn () => $request->session()->get('elevenlabs_status'),
                'facebook_status' => fn () => $request->session()->get('facebook_status'),
                'bot_status' => fn () => $request->session()->get('bot_status'),
                'order_slot_status' => fn () => $request->session()->get('order_slot_status'),
            ],

            'owlAdmin' => fn () => [
                ...config('owl-admin.branding', [
                    'brand_name' => config('owl-admin.brand_name', config('owl-admin.name', 'Service Admin')),
                    'logo_path' => config('owl-admin.logo_path', '/images/company-logo.svg'),
                ]),
                'ai' => $this->resolveAiStatus(),
                'instagram' => $this->resolveInstagramStatus(),
                'telegram' => $this->resolveTelegramStatus(),
            ],

            'statusLabels' => fn () => __('client.statuses'),

            'dialogsUnreadCount' => fn () => $request->user()
                ? $this->resolveDialogsUnreadCount()
                : 0,

            'instagramDialogsUnreadCount' => fn () => $request->user()
                ? $this->resolveDialogsUnreadCount('instagram')
                : 0,

            'facebookDialogsUnreadCount' => fn () => $request->user()
                ? $this->resolveDialogsUnreadCount('facebook')
                : 0,

            'applicationsCount' => fn () => $request->user()
                ? $this->resolveApplicationsCount()
                : 0,

            'instagramConnection' => fn () => $request->user()
                ? $this->resolveInstagramStatus()
                : null,

            'telegramConnection' => fn () => $request->user()
                ? $this->resolveTelegramStatus()
                : null,
        ];
    }

    private function resolveApplicationsCount(): int
    {
        try {
            if (! class_exists(\App\Models\BotReply::class)
                || ! \Illuminate\Support\Facades\Schema::hasTable('bot_replies')) {
                return 0;
            }

            return \App\Models\BotReply::query()
                ->whereNull('read_at')
                ->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function resolveDialogsUnreadCount(?string $channel = null): int
    {
        try {
            if (! class_exists(\App\Models\ConversationMessage::class)) {
                return 0;
            }

            if (! \Illuminate\Support\Facades\Schema::hasTable('conversation_messages')) {
                return 0;
            }

            return \App\Models\ConversationMessage::totalUnreadInboundCount($channel);
        } catch (\Throwable) {
            return 0;
        }
    }

    private function resolveLocale(Request $request): string
    {
        $allowed = ['en', 'ru', 'uk'];

        $sessionLocale = $request->session()->get('locale');
        if (is_string($sessionLocale) && in_array($sessionLocale, $allowed, true)) {
            return $sessionLocale;
        }

        $user = $request->user();
        if ($user && is_string($user->locale ?? null) && in_array($user->locale, $allowed, true)) {
            return $user->locale;
        }

        $default = config('app.locale', 'en');

        return in_array($default, $allowed, true) ? $default : 'en';
    }

    /**
     * @return array{connected: bool, provider: ?string, provider_label: ?string, model: ?string, status_label: string}
     */
    private function resolveAiStatus(): array
    {
        $fallback = [
            'connected' => false,
            'provider' => null,
            'provider_label' => null,
            'model' => null,
            'status_label' => __('client.ai.not_connected'),
        ];

        try {
            if (! class_exists(\App\Models\AiProviderSetting::class)) {
                return $fallback;
            }

            if (! \Illuminate\Support\Facades\Schema::hasTable('ai_provider_settings')) {
                return $fallback;
            }

            $active = \App\Models\AiProviderSetting::query()
                ->where('is_active', true)
                ->where('is_connected', true)
                ->first();

            if ($active === null) {
                return $fallback;
            }

            $providerLabel = $active->label ?: ucfirst((string) $active->provider);

            return [
                'connected' => true,
                'provider' => $active->provider,
                'provider_label' => $providerLabel,
                'model' => $active->active_model,
                'status_label' => sprintf(
                    __('client.ai.connected'),
                    $providerLabel,
                    $active->active_model ?? __('client.ai.unknown_model')
                ),
            ];
        } catch (\Throwable) {
            return $fallback;
        }
    }

    /**
     * @return array{connected: bool, status: string, username: ?string, status_label: string}
     */
    private function resolveInstagramStatus(): array
    {
        $fallback = [
            'connected' => false,
            'status' => 'not_configured',
            'username' => null,
            'status_label' => __('client.instagram.header_not_connected'),
        ];

        try {
            if (! class_exists(\App\Models\InstagramAccount::class)
                || ! \Illuminate\Support\Facades\Schema::hasTable('instagram_accounts')) {
                return $fallback;
            }

            $account = \App\Models\InstagramAccount::query()->orderBy('id')->first();
            if ($account === null) {
                return $fallback;
            }

            $username = trim((string) data_get($account->settings, 'instagram_username', ''));
            $username = $username !== '' ? ltrim($username, '@') : null;
            $connected = $account->isConnected();
            $status = (string) ($account->connection_status ?: \App\Models\InstagramAccount::STATUS_NOT_CONFIGURED);

            $label = match (true) {
                $connected && $username !== null => sprintf(__('client.instagram.header_connected_user'), $username),
                $connected => __('client.instagram.header_connected'),
                $status === \App\Models\InstagramAccount::STATUS_NEEDS_RECONNECT => __('client.instagram.header_needs_reconnect'),
                $status === \App\Models\InstagramAccount::STATUS_FAILED => __('client.instagram.header_failed'),
                $status === \App\Models\InstagramAccount::STATUS_DISCONNECTED => __('client.instagram.header_disconnected'),
                default => __('client.instagram.header_not_connected'),
            };

            return [
                'connected' => $connected,
                'status' => $status,
                'username' => $username,
                'status_label' => $label,
            ];
        } catch (\Throwable) {
            return $fallback;
        }
    }

    /**
     * @return array{connected: bool, status: string, username: ?string, status_label: string}
     */
    private function resolveTelegramStatus(): array
    {
        $fallback = [
            'connected' => false,
            'status' => 'not_configured',
            'username' => null,
            'status_label' => __('client.telegram.header_not_connected'),
        ];

        try {
            if (! class_exists(\App\Models\TelegramSetting::class)
                || ! \Illuminate\Support\Facades\Schema::hasTable('telegram_settings')) {
                return $fallback;
            }

            $settings = \App\Models\TelegramSetting::query()->orderBy('id')->first();
            if ($settings === null) {
                return $fallback;
            }

            $username = trim((string) ($settings->bot_username ?? ''));
            $username = $username !== '' ? ltrim($username, '@') : null;
            $connected = $settings->isBotConnected();
            $status = (string) ($settings->bot_status ?: \App\Models\TelegramSetting::STATUS_NOT_CONFIGURED);

            $label = match (true) {
                $connected && $username !== null => sprintf(__('client.telegram.header_connected_user'), $username),
                $connected => __('client.telegram.header_connected'),
                $status === \App\Models\TelegramSetting::STATUS_FAILED => __('client.telegram.header_failed'),
                default => __('client.telegram.header_not_connected'),
            };

            return [
                'connected' => $connected,
                'status' => $status,
                'username' => $username,
                'status_label' => $label,
            ];
        } catch (\Throwable) {
            return $fallback;
        }
    }
}
