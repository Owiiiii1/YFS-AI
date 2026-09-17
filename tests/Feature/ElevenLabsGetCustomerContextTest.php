<?php

namespace Tests\Feature;

use App\Services\Jfs\JfsReadService;
use App\Services\Voice\Identity\CustomerIdentityResult;
use App\Services\Voice\Identity\VoiceContactSessionResolver;
use App\Services\Voice\Tools\GetCustomerContextVoiceTool;
use App\Services\Voice\Tools\GetPublicShowsVoiceTool;
use App\Services\Voice\Tools\ResolveCustomerIdentityVoiceTool;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeJfsReadService;
use Tests\Support\MemoryVoiceContact;
use Tests\TestCase;

class ElevenLabsGetCustomerContextTest extends TestCase
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
        $this->jfs->events = [[
            'name' => 'YFS MIAMI',
            'city' => 'Miami',
            'location' => 'Miami Beach',
            'starts_at' => '2099-03-07 00:00:00',
            'ends_at' => null,
            'date_announced' => true,
            'is_past' => false,
            'description' => null,
        ]];
        $this->app->instance(JfsReadService::class, $this->jfs);
    }

    #[Test]
    public function auth_is_required(): void
    {
        $this->postJson('/api/voice/tools/customer-context')->assertUnauthorized();
        $this->postJson('/api/voice/tools/customer-context', [], [
            'Authorization' => 'Bearer wrong-token',
        ])->assertUnauthorized();
        $this->postJson('/api/voice/tools/customer-context', [], [
            'Authorization' => 'Bearer test-voice-runtime-token',
        ])->assertUnauthorized();
    }

    #[Test]
    public function smoke_without_identity_returns_identity_required_without_pii(): void
    {
        $sessions = \Mockery::mock(VoiceContactSessionResolver::class);
        $sessions->shouldReceive('findExistingTrusted')->once()->with(null, null)->andReturn(null);
        $this->app->instance(VoiceContactSessionResolver::class, $sessions);

        $this->postJson('/api/voice/tools/customer-context', [
            'customer_id' => 13,
            'name' => 'Olga Petrova',
            'phone' => '+15552220002',
            'email' => 'olga@example.com',
        ], $this->auth())
            ->assertOk()
            ->assertExactJson([
                'ok' => true,
                'tool' => GetCustomerContextVoiceTool::NAME,
                'status' => 'identity_required',
            ])
            ->assertDontSee('Olga', false)
            ->assertDontSee('Mia', false)
            ->assertDontSee('olga@example.com', false)
            ->assertDontSee('super-secret-jfs-password', false)
            ->assertDontSee('test-elevenlabs-tool-token', false);

        $this->assertSame(0, $this->jfs->loadCustomerContextCalls);
        $this->assertSame(0, $this->jfs->writeCalls);
    }

    #[Test]
    public function verified_session_returns_bound_customer_and_ignores_llm_identifiers(): void
    {
        $contact = new MemoryVoiceContact;
        $contact->forceFill([
            'metadata' => [
                'yfs_customer' => [
                    'status' => CustomerIdentityResult::UNIQUE,
                    'app_user_id' => 13,
                    'display_name' => 'Olga Petrova',
                    'match_method' => 'phone',
                    'matched_at' => '2026-01-01T00:00:00Z',
                ],
            ],
        ]);
        $sessions = \Mockery::mock(VoiceContactSessionResolver::class);
        $sessions->shouldReceive('findExistingTrusted')->once()->with('+15552220002', 'conv_olga')->andReturn($contact);
        $this->app->instance(VoiceContactSessionResolver::class, $sessions);

        $this->postJson('/api/voice/tools/customer-context', [
            'system__conversation_id' => 'conv_olga',
            'system__caller_id' => '+15552220002',
            'customer_id' => 99,
            'name' => 'Other Parent',
        ], $this->auth())
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('customer.display_name', 'Olga Petrova')
            ->assertJsonPath('children.0.display_name', 'Mia')
            ->assertJsonMissingPath('customer.app_user_id')
            ->assertJsonMissingPath('customer.email')
            ->assertDontSee('Other Parent', false)
            ->assertDontSee('super-secret-jfs-password', false);
    }

    #[Test]
    public function existing_identity_and_public_tools_still_work(): void
    {
        $sessions = \Mockery::mock(VoiceContactSessionResolver::class);
        $sessions->shouldReceive('findTrusted')->andReturn(null);
        $this->app->instance(VoiceContactSessionResolver::class, $sessions);

        $this->postJson('/api/voice/tools/resolve-customer-identity', [
            'name' => 'Olga Petrova',
        ], $this->auth())
            ->assertOk()
            ->assertJsonPath('tool', ResolveCustomerIdentityVoiceTool::NAME)
            ->assertJsonPath('status', 'unique');

        $this->postJson('/api/voice/tools/public-shows', [], $this->auth())
            ->assertOk()
            ->assertJsonPath('tool', GetPublicShowsVoiceTool::NAME)
            ->assertJsonPath('ok', true);
    }

    /**
     * @return array<string, string>
     */
    private function auth(): array
    {
        return ['Authorization' => 'Bearer test-elevenlabs-tool-token'];
    }
}
