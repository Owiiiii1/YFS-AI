<?php

namespace App\Services\Ai;

use App\Models\AiProviderSetting;
use App\Models\AiRoleConnection;
use RuntimeException;

class AiConnectionResolver
{
    public const ROLE_BOT_RUNTIME = 'bot_runtime';

    public const ROLE_PROMPT_ANALYSIS = 'prompt_analysis';

    /**
     * @return array{role:string,provider:string,model:string,api_key:string}
     */
    public function resolve(string $role = self::ROLE_BOT_RUNTIME): array
    {
        if (! in_array($role, [self::ROLE_BOT_RUNTIME, self::ROLE_PROMPT_ANALYSIS], true)) {
            throw new RuntimeException("Unsupported AI connection role [{$role}].");
        }

        $connection = AiRoleConnection::query()
            ->where('role', $role)
            ->where('is_connected', true)
            ->first();

        if ($connection === null && $role === self::ROLE_BOT_RUNTIME) {
            $setting = AiProviderSetting::query()
                ->where('is_active', true)
                ->where('is_connected', true)
                ->first();

            if ($setting === null || blank($setting->api_key) || blank($setting->active_model)) {
                throw new RuntimeException('AI provider is not configured or activated.');
            }

            return [
                'role' => $role,
                'provider' => (string) $setting->provider,
                'model' => (string) $setting->active_model,
                'api_key' => (string) $setting->api_key,
            ];
        }

        if ($connection === null || blank($connection->active_model)) {
            throw new RuntimeException("AI connection for role [{$role}] is not configured.");
        }

        $keySource = AiProviderSetting::query()
            ->where('provider', $connection->key_source_provider)
            ->where('is_connected', true)
            ->first();

        if ($keySource === null || blank($keySource->api_key)) {
            throw new RuntimeException("The {$connection->provider} API key is not connected.");
        }

        return [
            'role' => $role,
            'provider' => (string) $connection->provider,
            'model' => (string) $connection->active_model,
            'api_key' => (string) $keySource->api_key,
        ];
    }

    public function analysisConnection(): AiRoleConnection
    {
        return AiRoleConnection::query()->firstOrCreate(
            ['role' => AiRoleConnection::ROLE_PROMPT_ANALYSIS],
            [
                'provider' => 'gemini',
                'key_source_provider' => 'gemini',
                'active_model' => 'gemini-3.5-flash',
            ],
        );
    }

    public function runtimeConnection(): AiRoleConnection
    {
        $active = AiProviderSetting::query()
            ->where('is_active', true)
            ->where('is_connected', true)
            ->first();

        return AiRoleConnection::query()->firstOrCreate(
            ['role' => AiRoleConnection::ROLE_BOT_RUNTIME],
            [
                'provider' => (string) ($active?->provider ?? 'gemini'),
                'key_source_provider' => (string) ($active?->provider ?? 'gemini'),
                'active_model' => $active?->active_model,
                'is_connected' => $active !== null && filled($active->active_model),
            ],
        );
    }

    /**
     * @return array{bot_runtime:AiRoleConnection,prompt_analysis:AiRoleConnection}
     */
    public function roleConnections(): array
    {
        return [
            self::ROLE_BOT_RUNTIME => $this->runtimeConnection(),
            self::ROLE_PROMPT_ANALYSIS => $this->analysisConnection(),
        ];
    }
}
