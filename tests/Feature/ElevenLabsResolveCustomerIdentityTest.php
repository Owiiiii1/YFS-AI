<?php

namespace Tests\Feature;

use App\Services\Jfs\JfsReadService;
use App\Services\Voice\Identity\VoiceContactSessionResolver;
use App\Services\Voice\Tools\ResolveCustomerIdentityVoiceTool;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeJfsReadService;
use Tests\TestCase;

class ElevenLabsResolveCustomerIdentityTest extends TestCase
{
    private FakeJfsReadService $jfs;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.elevenlabs.tool_token' => 'test-elevenlabs-tool-token']);
        config(['services.voice_runtime.internal_token' => 'test-voice-runtime-token']);
        config(['database.connections.jfs.password' => 'super-secret-jfs-password']);

        $this->jfs = new FakeJfsReadService;
        $this->jfs->clients = [[
            'id' => 13,
            'name' => 'Olga Petrova',
            'language' => 'uk',
            'phone' => '+1-555-222-0002',
            'children' => ['Mia'],
        ]];
        $this->app->instance(JfsReadService::class, $this->jfs);

        $sessions = \Mockery::mock(VoiceContactSessionResolver::class);
        $sessions->shouldReceive('findTrusted')->andReturn(null);
        $this->app->instance(VoiceContactSessionResolver::class, $sessions);
    }

    #[Test]
    public function auth_is_required(): void
    {
        $this->postJson('/api/voice/tools/resolve-customer-identity', [
            'name' => 'Olga Petrova',
        ])->assertUnauthorized();

        $this->postJson('/api/voice/tools/resolve-customer-identity', [
            'name' => 'Olga Petrova',
        ], [
            'Authorization' => 'Bearer wrong-token',
        ])->assertUnauthorized();

        $this->postJson('/api/voice/tools/resolve-customer-identity', [
            'name' => 'Olga Petrova',
        ], [
            'Authorization' => 'Bearer test-voice-runtime-token',
        ])->assertUnauthorized();

        $this->postJson('/api/voice/tools/start-extended-identity-search')->assertUnauthorized();
        $this->postJson('/api/voice/tools/extended-identity-search-status')->assertUnauthorized();
    }

    #[Test]
    public function unique_name_returns_safe_contract(): void
    {
        $response = $this->postJson('/api/voice/tools/resolve-customer-identity', [
            'name' => 'Olga Petrova',
            'phone' => '+15552220002',
            'caller_id' => '+15552220002',
        ], $this->auth());

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'tool' => ResolveCustomerIdentityVoiceTool::NAME,
                'status' => 'unique',
                'next_action' => 'identified',
                'customer' => ['display_name' => 'Olga Petrova'],
            ])
            ->assertJsonMissingPath('customer.phone')
            ->assertJsonMissingPath('customer.email')
            ->assertJsonMissingPath('customer.app_user_id')
            ->assertDontSee('555-222', false)
            ->assertDontSee('test-elevenlabs-tool-token', false)
            ->assertDontSee('super-secret-jfs-password', false);
    }

    #[Test]
    public function llm_phone_is_not_treated_as_session_proof(): void
    {
        $sessions = \Mockery::mock(VoiceContactSessionResolver::class);
        $sessions->shouldReceive('findTrusted')->once()->with(null, null)->andReturn(null);
        $this->app->instance(VoiceContactSessionResolver::class, $sessions);

        $this->postJson('/api/voice/tools/resolve-customer-identity', [
            'name' => 'Olga Petrova',
            'phone' => '+15552220002',
            'caller_id' => '+15552220002',
        ], $this->auth())->assertOk()->assertJson([
            'status' => 'unique',
            'next_action' => 'identified',
        ]);
    }

    #[Test]
    public function trusted_system_caller_id_is_used_for_session_lookup(): void
    {
        $sessions = \Mockery::mock(VoiceContactSessionResolver::class);
        $sessions->shouldReceive('findTrusted')->once()->with('+15552220002', 'conv_123')->andReturn(null);
        $this->app->instance(VoiceContactSessionResolver::class, $sessions);

        $this->postJson('/api/voice/tools/resolve-customer-identity', [
            'name' => 'Olga Petrova',
            'system__caller_id' => '+15552220002',
            'system__conversation_id' => 'conv_123',
            'phone' => '+19999999999',
        ], $this->auth())->assertOk();
    }

    /**
     * @return array<string, string>
     */
    private function auth(): array
    {
        return ['Authorization' => 'Bearer test-elevenlabs-tool-token'];
    }
}
