<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AiProviderSetting;
use App\Models\AiRoleConnection;
use App\Services\Ai\AiConnectionResolver;
use App\Services\Ai\AiProviderManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class AiSettingsController extends Controller
{
    public function __construct(
        private readonly AiProviderManager $providerManager,
        private readonly AiConnectionResolver $connectionResolver,
    ) {}

    public function index(): RedirectResponse
    {
        return redirect()->route('settings.index', ['tab' => 'ai']);
    }

    public function saveKey(Request $request, string $provider): RedirectResponse
    {
        $validated = $this->validateAi($request, [
            'provider' => ['required', Rule::in(['openai', 'anthropic', 'gemini'])],
            'api_key' => ['required', 'string', 'max:4096'],
        ]);

        if ($validated['provider'] !== $provider) {
            abort(422, 'Provider mismatch.');
        }

        $setting = $this->setting($provider);
        $setting->fill([
            'api_key' => trim($validated['api_key']),
            'last_error' => null,
            'is_connected' => false,
            'available_models' => null,
            'last_checked_at' => null,
        ])->save();

        return $this->backToAi()->with('success', 'API key saved.');
    }

    public function check(Request $request, string $provider): RedirectResponse
    {
        $validated = $this->validateAi($request, [
            'provider' => ['required', Rule::in(['openai', 'anthropic', 'gemini'])],
        ]);

        if ($validated['provider'] !== $provider) {
            abort(422, 'Provider mismatch.');
        }

        $setting = $this->setting($provider);
        if (! filled($setting->api_key)) {
            return $this->backToAi()->withErrors(['ai' => 'Save API key before checking connection.']);
        }

        try {
            $models = $this->providerManager->listModels($provider, (string) $setting->api_key);
            $setting->fill([
                'is_connected' => true,
                'available_models' => $models,
                'last_checked_at' => Carbon::now(),
                'last_error' => null,
            ])->save();

            return $this->backToAi()->with('success', 'Connection checked. Models loaded.');
        } catch (Throwable $e) {
            $setting->fill([
                'is_connected' => false,
                'available_models' => null,
                'last_checked_at' => Carbon::now(),
                'last_error' => $e->getMessage(),
            ])->save();

            return $this->backToAi()->withErrors(['ai' => $e->getMessage()]);
        }
    }

    public function activate(Request $request, string $provider): RedirectResponse
    {
        $validated = $this->validateAi($request, [
            'provider' => ['required', Rule::in(['openai', 'anthropic', 'gemini'])],
            'model' => ['required', 'string', 'max:255'],
            'confirmed_switch' => ['sometimes', 'boolean'],
        ]);

        if ($validated['provider'] !== $provider) {
            abort(422, 'Provider mismatch.');
        }

        return $this->activateForRole(
            $provider,
            AiRoleConnection::ROLE_BOT_RUNTIME,
            $validated['model'],
            (bool) ($validated['confirmed_switch'] ?? false),
        );
    }

    public function activateRole(Request $request, string $provider, string $role): RedirectResponse
    {
        $validated = $this->validateAi($request, [
            'provider' => ['required', Rule::in(['openai', 'anthropic', 'gemini'])],
            'role' => ['required', Rule::in([
                AiRoleConnection::ROLE_BOT_RUNTIME,
                AiRoleConnection::ROLE_PROMPT_ANALYSIS,
            ])],
            'model' => ['required', 'string', 'max:255'],
            'confirmed_switch' => ['required', 'boolean'],
        ]);
        if ($validated['provider'] !== $provider || $validated['role'] !== $role) {
            abort(422, 'AI role assignment mismatch.');
        }

        return $this->activateForRole(
            $provider,
            $role,
            $validated['model'],
            (bool) $validated['confirmed_switch'],
        );
    }

    public function deactivate(): RedirectResponse
    {
        AiProviderSetting::query()
            ->whereIn('provider', AiProviderSetting::LLM_PROVIDERS)
            ->update([
                'is_active' => false,
                'active_model' => null,
            ]);

        return $this->backToAi()->with('success', 'AI provider deactivated.');
    }

    public function checkAnalysis(): RedirectResponse
    {
        $gemini = $this->setting('gemini');
        $connection = $this->connectionResolver->analysisConnection();

        if (! filled($gemini->api_key)) {
            return $this->backToAi()->withErrors(['ai_analysis' => 'Сначала сохраните API-ключ Gemini.']);
        }

        try {
            $models = $this->providerManager->listModels('gemini', (string) $gemini->api_key);
            $available = collect($models)->pluck('id')->all();
            $model = (string) ($connection->active_model ?: 'gemini-3.5-flash');

            if (! in_array($model, $available, true)) {
                throw new \RuntimeException("Модель {$model} недоступна для текущего Gemini API-ключа.");
            }

            $gemini->forceFill([
                'is_connected' => true,
                'available_models' => $models,
                'last_checked_at' => Carbon::now(),
                'last_error' => null,
            ])->save();
            $connection->forceFill([
                'is_connected' => true,
                'last_checked_at' => Carbon::now(),
                'last_error' => null,
            ])->save();

            return $this->backToAi()->with('success', 'Подключение AI-анализа проверено.');
        } catch (Throwable $exception) {
            $connection->forceFill([
                'is_connected' => false,
                'last_checked_at' => Carbon::now(),
                'last_error' => $exception->getMessage(),
            ])->save();

            return $this->backToAi()->withErrors(['ai_analysis' => $exception->getMessage()]);
        }
    }

    public function activateAnalysis(Request $request): RedirectResponse
    {
        $validated = $this->validateAi($request, [
            'model' => ['required', 'string', 'max:255'],
            'confirmed_switch' => ['sometimes', 'boolean'],
        ]);

        return $this->activateForRole(
            'gemini',
            AiRoleConnection::ROLE_PROMPT_ANALYSIS,
            $validated['model'],
            (bool) ($validated['confirmed_switch'] ?? false),
        );
    }

    public function deactivateAnalysis(): RedirectResponse
    {
        AiRoleConnection::query()
            ->where('role', AiRoleConnection::ROLE_PROMPT_ANALYSIS)
            ->update(['is_connected' => false]);

        return $this->backToAi()->with('success', 'Управление AI-анализом отключено.');
    }

    public function deactivateRole(string $role): RedirectResponse
    {
        if (! in_array($role, [
            AiRoleConnection::ROLE_BOT_RUNTIME,
            AiRoleConnection::ROLE_PROMPT_ANALYSIS,
        ], true)) {
            abort(404);
        }

        DB::transaction(function () use ($role): void {
            AiRoleConnection::query()->where('role', $role)->update(['is_connected' => false]);
            if ($role === AiRoleConnection::ROLE_BOT_RUNTIME) {
                AiProviderSetting::query()
                    ->whereIn('provider', AiProviderSetting::LLM_PROVIDERS)
                    ->update(['is_active' => false]);
            }
        });

        return $this->backToAi()->with('success', 'AI role deactivated.');
    }

    private function activateForRole(
        string $provider,
        string $role,
        string $model,
        bool $confirmedSwitch,
    ): RedirectResponse {
        $setting = $this->setting($provider);
        $available = collect($setting->available_models ?? [])->pluck('id')->filter()->values()->all();
        if (! $setting->is_connected || ! in_array($model, $available, true)) {
            return $this->backToAi()->withErrors([
                'ai' => 'Selected model is not available for this connected provider. Re-check connection.',
            ]);
        }

        $current = AiRoleConnection::query()->where('role', $role)->first();
        $isSwitch = $current?->is_connected
            && ($current->provider !== $provider || $current->active_model !== $model);
        if ($isSwitch && ! $confirmedSwitch) {
            throw ValidationException::withMessages([
                'ai' => 'Подтвердите переключение: текущий активный агент этой роли будет выключен.',
            ])->redirectTo(route('settings.index', ['tab' => 'ai']));
        }

        DB::transaction(function () use ($provider, $role, $model, $setting): void {
            AiRoleConnection::query()->updateOrCreate(
                ['role' => $role],
                [
                    'provider' => $provider,
                    'key_source_provider' => $provider,
                    'active_model' => $model,
                    'is_connected' => true,
                    'last_checked_at' => Carbon::now(),
                    'last_error' => null,
                ],
            );

            if ($role === AiRoleConnection::ROLE_BOT_RUNTIME) {
                AiProviderSetting::query()
                    ->whereIn('provider', AiProviderSetting::LLM_PROVIDERS)
                    ->update(['is_active' => false]);
                $setting->forceFill([
                    'is_active' => true,
                    'active_model' => $model,
                    'last_error' => null,
                ])->save();
            }
        });

        return $this->backToAi()->with('success', $role === AiRoleConnection::ROLE_BOT_RUNTIME
            ? 'Агент чат-бота активирован.'
            : 'Агент AI-анализа активирован.');
    }

    private function backToAi(): RedirectResponse
    {
        session(['settings_tab' => 'ai']);

        return redirect()->route('settings.index', ['tab' => 'ai']);
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    private function validateAi(Request $request, array $rules): array
    {
        try {
            return $request->validate($rules);
        } catch (ValidationException $exception) {
            throw $exception->redirectTo(route('settings.index', ['tab' => 'ai']));
        }
    }

    private function ensureProvidersExist(): void
    {
        foreach ($this->providerManager->providers() as $provider) {
            AiProviderSetting::query()->firstOrCreate(
                ['provider' => $provider['provider']],
                ['label' => $provider['label']]
            );
        }
    }

    private function setting(string $provider): AiProviderSetting
    {
        $this->ensureProvidersExist();

        /** @var AiProviderSetting $setting */
        $setting = AiProviderSetting::query()->where('provider', $provider)->firstOrFail();

        return $setting;
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
