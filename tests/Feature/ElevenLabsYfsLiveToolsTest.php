<?php

namespace Tests\Feature;

use App\Services\Jfs\JfsReadService;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeJfsReadService;
use Tests\TestCase;

class ElevenLabsYfsLiveToolsTest extends TestCase
{
    private FakeJfsReadService $jfs;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.elevenlabs.tool_token' => 'test-elevenlabs-tool-token']);
        config(['services.voice_runtime.internal_token' => 'test-voice-runtime-token']);
        config(['database.connections.jfs.password' => 'super-secret-jfs-password']);

        $this->jfs = new FakeJfsReadService;
        $this->jfs->events = [
            [
                'name' => 'YFS CHICAGO',
                'city' => 'Chicago',
                'location' => 'Illinois',
                'starts_at' => null,
                'ends_at' => null,
                'date_announced' => false,
                'is_past' => false,
                'description' => 'Chicago public note',
            ],
            [
                'name' => 'YFS MIAMI',
                'city' => 'Miami',
                'location' => 'Miami Beach',
                'starts_at' => '2099-03-07 00:00:00',
                'ends_at' => null,
                'date_announced' => true,
                'is_past' => false,
                'description' => null,
            ],
        ];
        $this->jfs->lineups = [
            [
                'name' => 'YFS CHICAGO',
                'city' => 'Chicago',
                'starts_at' => null,
                'date_announced' => false,
                'is_past' => false,
                'brand_count' => 0,
                'brands' => [],
            ],
            [
                'name' => 'YFS MIAMI',
                'city' => 'Miami',
                'starts_at' => '2099-03-07 00:00:00',
                'date_announced' => true,
                'is_past' => false,
                'brand_count' => 2,
                'brands' => ['Alpha', 'Beta'],
            ],
        ];

        $this->app->instance(JfsReadService::class, $this->jfs);
    }

    #[Test]
    public function public_shows_requires_elevenlabs_bearer_auth(): void
    {
        $this->postJson('/api/voice/tools/public-shows')->assertUnauthorized();
        $this->postJson('/api/voice/tools/public-shows', [], [
            'Authorization' => 'Bearer wrong-token',
        ])->assertUnauthorized();
        $this->postJson('/api/voice/tools/public-shows', [], [
            'Authorization' => 'Bearer test-voice-runtime-token',
        ])->assertUnauthorized();
    }

    #[Test]
    public function show_brands_requires_elevenlabs_bearer_auth(): void
    {
        $this->postJson('/api/voice/tools/show-brands')->assertUnauthorized();
        $this->postJson('/api/voice/tools/show-brands', [], [
            'Authorization' => 'Bearer wrong-token',
        ])->assertUnauthorized();
    }

    #[Test]
    public function public_shows_returns_jfs_payload_and_filters(): void
    {
        $this->postJson('/api/voice/tools/public-shows', [], $this->auth())
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('tool', 'get_public_shows')
            ->assertJsonPath('source', 'yfs_core')
            ->assertJsonPath('count', 2)
            ->assertJsonPath('results.0.starts_at', null)
            ->assertJsonPath('results.0.date_announced', false)
            ->assertJsonMissingPath('error')
            ->assertDontSee('super-secret-jfs-password', false)
            ->assertDontSee('test-elevenlabs-tool-token', false)
            ->assertDontSee('JFS_DB_', false);

        $this->assertSame(1, $this->jfs->publicEventsCalls);
        $this->assertSame(0, $this->jfs->writeCalls);

        $this->postJson('/api/voice/tools/public-shows', ['city' => 'Miami'], $this->auth())
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('results.0.name', 'YFS MIAMI');

        $this->postJson('/api/voice/tools/public-shows', ['show_name' => 'chicago'], $this->auth())
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('results.0.city', 'Chicago');

        $this->postJson('/api/voice/tools/public-shows', ['city' => 'Paris'], $this->auth())
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'tool' => 'get_public_shows',
                'source' => 'yfs_core',
                'results' => [],
                'count' => 0,
                'message' => 'no_matching_shows',
            ]);
    }

    #[Test]
    public function show_brands_returns_unpublished_lineup_without_invented_brands(): void
    {
        $this->postJson('/api/voice/tools/show-brands', ['show_name' => 'CHICAGO'], $this->auth())
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('tool', 'get_show_brands')
            ->assertJsonPath('source', 'yfs_core')
            ->assertJsonPath('count', 1)
            ->assertJsonPath('results.0.brands', [])
            ->assertJsonPath('results.0.brand_count', 0)
            ->assertJsonPath('results.0.lineup_published', false)
            ->assertDontSee('Alpha', false);

        $this->assertSame(1, $this->jfs->publicBrandLineupsCalls);
        $this->assertSame(0, $this->jfs->writeCalls);

        $this->postJson('/api/voice/tools/show-brands', ['city' => 'Miami'], $this->auth())
            ->assertOk()
            ->assertJsonPath('results.0.brands.0', 'Alpha')
            ->assertJsonPath('results.0.lineup_published', true);
    }

    #[Test]
    public function unavailable_jfs_returns_safe_json_without_http_500(): void
    {
        $this->jfs->configured = false;

        $this->postJson('/api/voice/tools/public-shows', [], $this->auth())
            ->assertOk()
            ->assertJson([
                'ok' => false,
                'tool' => 'get_public_shows',
                'source' => 'yfs_core',
                'results' => [],
                'error' => 'source_unavailable',
            ])
            ->assertJsonMissingPath('exception')
            ->assertJsonMissingPath('trace')
            ->assertDontSee('super-secret-jfs-password', false);

        $this->postJson('/api/voice/tools/show-brands', [], $this->auth())
            ->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'source_unavailable');
    }

    #[Test]
    public function test_context_endpoint_is_unchanged(): void
    {
        $this->postJson('/api/voice/tools/test-context', [], $this->auth())
            ->assertOk()
            ->assertExactJson([
                'event_name' => 'YFS Test Event',
                'status' => 'active',
                'message' => 'Voice session context is working',
                'source' => 'yfs_ai_test',
            ]);
    }

    /**
     * @return array<string, string>
     */
    private function auth(): array
    {
        return ['Authorization' => 'Bearer test-elevenlabs-tool-token'];
    }
}
