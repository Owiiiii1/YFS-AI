<?php

namespace Tests\Feature;

use App\Models\AiProviderSetting;
use App\Models\AiRoleConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ElevenLabsSettingsAndVoiceRuntimeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite is required for isolated feature tests.');
        }

        parent::setUp();

        config(['services.voice_runtime.internal_token' => 'test-voice-runtime-token']);
    }

    #[Test]
    public function settings_page_includes_elevenlabs_tab_without_exposing_secret(): void
    {
        AiProviderSetting::query()->create([
            'provider' => AiProviderSetting::PROVIDER_ELEVENLABS,
            'label' => 'ElevenLabs',
            'api_key' => 'sk_test_elevenlabs_secret_value',
            'is_connected' => true,
        ]);

        $this->actingAs(User::factory()->create())
            ->get(route('settings.index', ['tab' => 'elevenlabs']))
            ->assertOk()
            ->assertDontSee('sk_test_elevenlabs_secret_value', false)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Settings/Index')
                ->where('tab', 'elevenlabs')
                ->where('elevenlabs.status', 'connected')
                ->where('elevenlabs.has_api_key', true)
                ->where('elevenlabs.api_key_masked', fn ($mask) => is_string($mask) && ! str_contains($mask, 'sk_test_elevenlabs_secret_value'))
            );
    }

    #[Test]
    public function empty_save_does_not_delete_existing_key(): void
    {
        Http::fake([
            'api.elevenlabs.io/*' => Http::response(['ok' => true], 200),
        ]);

        AiProviderSetting::query()->create([
            'provider' => AiProviderSetting::PROVIDER_ELEVENLABS,
            'label' => 'ElevenLabs',
            'api_key' => 'keep-this-key-please',
            'is_connected' => false,
        ]);

        $this->actingAs(User::factory()->create())
            ->post(route('elevenlabs.save'), ['api_key' => ''])
            ->assertRedirect(route('settings.index', ['tab' => 'elevenlabs']));

        $setting = AiProviderSetting::query()->where('provider', 'elevenlabs')->first();
        $this->assertSame('keep-this-key-please', $setting?->api_key);
        $this->assertTrue((bool) $setting?->is_connected);
    }

    #[Test]
    public function valid_key_marks_connected_and_invalid_key_marks_failed(): void
    {
        Http::fake([
            'api.elevenlabs.io/*' => Http::response(['ok' => true], 200),
        ]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('elevenlabs.save'), ['api_key' => 'good-key'])
            ->assertRedirect(route('settings.index', ['tab' => 'elevenlabs']));

        $setting = AiProviderSetting::query()->where('provider', 'elevenlabs')->first();
        $this->assertTrue((bool) $setting?->is_connected);
        $this->assertSame('good-key', $setting?->api_key);

        Http::fake([
            'api.elevenlabs.io/*' => Http::response(['detail' => ['status' => 'authentication_error']], 401),
        ]);

        $this->actingAs($user)
            ->from(route('settings.index', ['tab' => 'elevenlabs']))
            ->post(route('elevenlabs.save'), ['api_key' => 'bad-key'])
            ->assertRedirect(route('settings.index', ['tab' => 'elevenlabs']))
            ->assertSessionHasErrors('elevenlabs');

        $setting->refresh();
        $this->assertFalse((bool) $setting->is_connected);
        $this->assertSame('bad-key', $setting->api_key);
        $this->assertSame('ElevenLabs rejected the API key.', $setting->last_error);
    }

    #[Test]
    public function scoped_key_without_user_read_still_connects_via_speech_engine(): void
    {
        Http::fake([
            'https://api.elevenlabs.io/v1/speech-engine' => Http::response(['speech_engines' => []], 200),
            'https://api.elevenlabs.io/v1/user' => Http::response(['detail' => ['status' => 'missing_permissions']], 401),
        ]);

        $this->actingAs(User::factory()->create())
            ->post(route('elevenlabs.save'), ['api_key' => 'scoped-key'])
            ->assertRedirect(route('settings.index', ['tab' => 'elevenlabs']));

        $setting = AiProviderSetting::query()->where('provider', 'elevenlabs')->first();
        $this->assertTrue((bool) $setting?->is_connected);
    }

    #[Test]
    public function missing_key_is_not_configured(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('elevenlabs.save'), ['api_key' => ''])
            ->assertRedirect(route('settings.index', ['tab' => 'elevenlabs']));

        $frontend = app(\App\Services\ElevenLabs\ElevenLabsSettingsService::class)->forFrontend();
        $this->assertSame('not_configured', $frontend['status']);
        $this->assertFalse($frontend['has_api_key']);
    }

    #[Test]
    public function internal_config_requires_token_and_returns_laravel_credentials(): void
    {
        AiProviderSetting::query()->create([
            'provider' => 'openai',
            'label' => 'OpenAI',
            'api_key' => 'openai-runtime-key',
            'is_connected' => true,
            'is_active' => true,
            'active_model' => 'gpt-5.6-luna',
        ]);
        AiRoleConnection::query()->create([
            'role' => AiRoleConnection::ROLE_BOT_RUNTIME,
            'provider' => 'openai',
            'key_source_provider' => 'openai',
            'active_model' => 'gpt-5.6-luna',
            'is_connected' => true,
        ]);
        AiProviderSetting::query()->create([
            'provider' => AiProviderSetting::PROVIDER_ELEVENLABS,
            'label' => 'ElevenLabs',
            'api_key' => 'el-runtime-key',
            'is_connected' => true,
        ]);

        $this->getJson('/api/internal/voice-runtime/config')
            ->assertUnauthorized();

        $this->getJson('/api/internal/voice-runtime/config', [
            'Authorization' => 'Bearer wrong-token',
        ])->assertUnauthorized();

        $this->getJson('/api/internal/voice-runtime/config', [
            'Authorization' => 'Bearer test-voice-runtime-token',
        ])->assertOk()->assertJson([
            'llm' => [
                'provider' => 'openai',
                'model' => 'gpt-5.6-luna',
                'api_key' => 'openai-runtime-key',
            ],
            'elevenlabs' => [
                'api_key' => 'el-runtime-key',
            ],
        ]);
    }

    #[Test]
    public function ai_settings_tab_still_loads_openai_without_elevenlabs_card(): void
    {
        AiProviderSetting::query()->create([
            'provider' => 'openai',
            'label' => 'OpenAI',
            'api_key' => 'openai-admin-key',
            'is_connected' => true,
            'is_active' => true,
            'active_model' => 'gpt-5.6-luna',
        ]);
        AiProviderSetting::query()->create([
            'provider' => AiProviderSetting::PROVIDER_ELEVENLABS,
            'label' => 'ElevenLabs',
            'api_key' => 'should-not-appear-in-ai-tab',
            'is_connected' => true,
        ]);

        $this->actingAs(User::factory()->create())
            ->get(route('settings.index', ['tab' => 'ai']))
            ->assertOk()
            ->assertDontSee('should-not-appear-in-ai-tab', false)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Settings/Index')
                ->where('tab', 'ai')
                ->has('providers')
                ->where('providers', fn ($providers) => collect($providers)->pluck('provider')->doesntContain('elevenlabs'))
            );
    }
}
