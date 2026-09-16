<?php

namespace Tests\Feature;

use App\Models\VoiceAssistantSetting;
use App\Models\VoiceCall;
use App\Models\VoiceContact;
use App\Services\Jfs\JfsReadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeJfsReadService;
use Tests\TestCase;

class ElevenLabsConversationInitiationDatabaseTest extends TestCase
{
    use RefreshDatabase;

    private FakeJfsReadService $jfs;

    protected function setUp(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite is required for isolated feature tests.');
        }

        parent::setUp();

        config(['services.elevenlabs.tool_token' => 'test-elevenlabs-tool-token']);
        config(['services.voice_runtime.internal_token' => 'test-voice-runtime-token']);

        $this->jfs = new FakeJfsReadService;
        $this->app->instance(JfsReadService::class, $this->jfs);
    }

    #[Test]
    public function valid_auth_returns_elevenlabs_initiation_payload_without_secrets(): void
    {
        VoiceAssistantSetting::query()->create([
            'key' => 'general',
            'title' => 'General rules',
            'instructions' => 'Use the app first.',
            'enabled' => true,
            'sort_order' => 1,
        ]);

        $response = $this->postJson('/api/voice/elevenlabs/conversation-initiation', [
            'caller_id' => '+15551234567',
            'agent_id' => 'agent_test',
            'called_number' => '+15557654321',
            'call_sid' => 'CA_test',
            'conversation_id' => 'conv_test',
        ], [
            'Authorization' => 'Bearer test-elevenlabs-tool-token',
        ]);

        $response->assertOk()
            ->assertDontSee('test-elevenlabs-tool-token', false)
            ->assertDontSee('test-voice-runtime-token', false);

        $payload = $response->json();
        $this->assertSame('conversation_initiation_client_data', $payload['type']);
        $this->assertSame(['type', 'conversation_config_override'], array_keys($payload));
        $prompt = $payload['conversation_config_override']['agent']['prompt']['prompt'];
        $this->assertStringContainsString('Use the app first.', $prompt);
        $this->assertStringContainsString('Customer Support Voice Assistant', $prompt);
        $this->assertStringContainsString('get_public_shows', $prompt);
        $this->assertStringContainsString('get_show_brands', $prompt);
        $this->assertStringContainsString('E. CALLER IDENTITY', $prompt);
        $this->assertArrayNotHasKey('llm', $payload['conversation_config_override']['agent']['prompt']);
        $this->assertArrayNotHasKey('language', $payload['conversation_config_override']['agent']);
    }

    #[Test]
    public function unknown_caller_creates_a_contact_without_language_override_or_calls_count(): void
    {
        VoiceAssistantSetting::query()->create([
            'key' => 'general',
            'title' => 'General rules',
            'instructions' => 'Use the app first.',
            'enabled' => true,
            'sort_order' => 1,
        ]);

        $this->postJson('/api/voice/elevenlabs/conversation-initiation', [
            'caller_id' => '+1 (555) 123-4567',
        ], [
            'Authorization' => 'Bearer test-elevenlabs-tool-token',
        ])->assertOk()
            ->assertJsonMissingPath('conversation_config_override.agent.language');

        $this->postJson('/api/voice/elevenlabs/conversation-initiation', [
            'caller_id' => '15551234567',
        ], [
            'Authorization' => 'Bearer test-elevenlabs-tool-token',
        ])->assertOk();

        $this->assertSame(1, VoiceContact::query()->count());
        $contact = VoiceContact::query()->first();
        $this->assertSame('+15551234567', $contact->phone_normalized);
        $this->assertSame(0, $contact->calls_count);
        $this->assertNull($contact->preferred_language);
        $this->assertSame(0, VoiceCall::query()->count());
    }

    #[Test]
    public function known_caller_with_russian_preferred_language_gets_language_override(): void
    {
        $this->assertLanguageOverride('ru', '+15551230001');
    }

    #[Test]
    public function known_caller_with_ukrainian_preferred_language_gets_language_override(): void
    {
        $this->assertLanguageOverride('uk', '+15551230002');
    }

    #[Test]
    public function unsupported_preferred_language_does_not_create_an_override(): void
    {
        VoiceAssistantSetting::query()->create([
            'key' => 'general',
            'title' => 'General rules',
            'instructions' => 'Use the app first.',
            'enabled' => true,
            'sort_order' => 1,
        ]);

        VoiceContact::query()->create([
            'phone_normalized' => '+15551230003',
            'phone_display' => '+15551230003',
            'preferred_language' => 'fr',
            'calls_count' => 0,
        ]);

        $payload = $this->postJson('/api/voice/elevenlabs/conversation-initiation', [
            'caller_id' => '+15551230003',
        ], [
            'Authorization' => 'Bearer test-elevenlabs-tool-token',
        ])->assertOk()->json();

        $this->assertSame(['type', 'conversation_config_override'], array_keys($payload));
        $this->assertArrayHasKey('prompt', $payload['conversation_config_override']['agent']);
        $this->assertArrayNotHasKey('language', $payload['conversation_config_override']['agent']);
        $this->assertSame(
            ['prompt' => ['prompt' => $payload['conversation_config_override']['agent']['prompt']['prompt']]],
            ['prompt' => $payload['conversation_config_override']['agent']['prompt']],
        );
    }

    #[Test]
    public function extra_elevenlabs_fields_do_not_fail_the_request(): void
    {
        VoiceAssistantSetting::query()->create([
            'key' => 'general',
            'title' => 'General rules',
            'instructions' => 'Use the app first.',
            'enabled' => true,
            'sort_order' => 1,
        ]);

        $this->postJson('/api/voice/elevenlabs/conversation-initiation', [
            'caller_id' => '+15551234567',
            'agent_id' => 'agent_test',
            'called_number' => '+15557654321',
            'call_sid' => 'CA_test',
            'conversation_id' => 'conv_test',
            'call_id' => 'sip-call-id',
            'sip_headers' => ['X-Example' => '1'],
        ], [
            'Authorization' => 'Bearer test-elevenlabs-tool-token',
        ])->assertOk()
            ->assertJsonPath('type', 'conversation_initiation_client_data');
    }

    #[Test]
    public function disabled_sections_are_excluded_and_sort_order_is_respected(): void
    {
        VoiceAssistantSetting::query()->create([
            'key' => 'payments',
            'title' => 'Payments',
            'instructions' => 'Payment body.',
            'enabled' => true,
            'sort_order' => 20,
        ]);
        VoiceAssistantSetting::query()->create([
            'key' => 'general',
            'title' => 'General rules',
            'instructions' => 'General body.',
            'enabled' => true,
            'sort_order' => 1,
        ]);
        VoiceAssistantSetting::query()->create([
            'key' => 'backstage',
            'title' => 'Backstage',
            'instructions' => 'Hidden backstage body.',
            'enabled' => false,
            'sort_order' => 2,
        ]);

        $prompt = $this->postJson('/api/voice/elevenlabs/conversation-initiation', [], [
            'Authorization' => 'Bearer test-elevenlabs-tool-token',
        ])->assertOk()->json('conversation_config_override.agent.prompt.prompt');

        $this->assertStringContainsString('General body.', $prompt);
        $this->assertStringContainsString('Payment body.', $prompt);
        $this->assertStringNotContainsString('Hidden backstage body.', $prompt);
        $this->assertTrue(strpos($prompt, '## General rules') < strpos($prompt, '## Payments'));
    }

    #[Test]
    public function changing_instructions_changes_the_next_prompt(): void
    {
        $section = VoiceAssistantSetting::query()->create([
            'key' => 'general',
            'title' => 'General rules',
            'instructions' => 'Original policy.',
            'enabled' => true,
            'sort_order' => 1,
        ]);

        $first = $this->postJson('/api/voice/elevenlabs/conversation-initiation', [], [
            'Authorization' => 'Bearer test-elevenlabs-tool-token',
        ])->assertOk()->json('conversation_config_override.agent.prompt.prompt');

        $section->forceFill(['instructions' => 'Edited policy.'])->save();

        $second = $this->postJson('/api/voice/elevenlabs/conversation-initiation', [], [
            'Authorization' => 'Bearer test-elevenlabs-tool-token',
        ])->assertOk()->json('conversation_config_override.agent.prompt.prompt');

        $this->assertStringContainsString('Original policy.', $first);
        $this->assertStringContainsString('Edited policy.', $second);
        $this->assertStringNotContainsString('Original policy.', $second);
    }

    private function assertLanguageOverride(string $language, string $phone): void
    {
        VoiceAssistantSetting::query()->create([
            'key' => 'general',
            'title' => 'General rules',
            'instructions' => 'Use the app first.',
            'enabled' => true,
            'sort_order' => 1,
        ]);

        VoiceContact::query()->create([
            'phone_normalized' => $phone,
            'phone_display' => $phone,
            'preferred_language' => $language,
            'calls_count' => 3,
        ]);

        $payload = $this->postJson('/api/voice/elevenlabs/conversation-initiation', [
            'caller_id' => $phone,
        ], [
            'Authorization' => 'Bearer test-elevenlabs-tool-token',
        ])->assertOk()->json();

        $this->assertSame('conversation_initiation_client_data', $payload['type']);
        $this->assertSame(['type', 'conversation_config_override'], array_keys($payload));
        $this->assertSame($language, $payload['conversation_config_override']['agent']['language']);
        $this->assertArrayHasKey('prompt', $payload['conversation_config_override']['agent']);
        $this->assertSame(['prompt'], array_keys($payload['conversation_config_override']['agent']['prompt']));
        $this->assertStringContainsString('Use the app first.', $payload['conversation_config_override']['agent']['prompt']['prompt']);
        $this->assertSame(3, VoiceContact::query()->where('phone_normalized', $phone)->value('calls_count'));
    }

    #[Test]
    public function unique_phone_stores_compact_identity_without_selecting_on_ambiguous(): void
    {
        VoiceAssistantSetting::query()->create([
            'key' => 'general',
            'title' => 'General rules',
            'instructions' => 'Use the app first.',
            'enabled' => true,
            'sort_order' => 1,
        ]);

        $this->jfs->clients = [
            [
                'id' => 31,
                'name' => 'Test Parent',
                'language' => 'en',
                'phone' => '+1-555-777-0007',
                'children' => ['Kid'],
            ],
            [
                'id' => 32,
                'name' => 'Other Parent',
                'language' => 'en',
                'phone' => '+1-555-888-0008',
                'children' => [],
            ],
            [
                'id' => 33,
                'name' => 'Twin Parent',
                'language' => 'en',
                'phone' => '+1-555-888-0008',
                'children' => [],
            ],
        ];

        $unique = $this->postJson('/api/voice/elevenlabs/conversation-initiation', [
            'caller_id' => '+15557770007',
        ], [
            'Authorization' => 'Bearer test-elevenlabs-tool-token',
        ])->assertOk()->json();

        $this->assertSame(['type', 'conversation_config_override'], array_keys($unique));
        $this->assertStringContainsString('Test Parent', $unique['conversation_config_override']['agent']['prompt']['prompt']);
        $this->assertStringNotContainsString('31', $unique['conversation_config_override']['agent']['prompt']['prompt']);

        $contact = VoiceContact::query()->where('phone_normalized', '+15557770007')->first();
        $this->assertNotNull($contact);
        $this->assertSame('unique', $contact->metadata['yfs_customer']['status'] ?? null);
        $this->assertSame(31, $contact->metadata['yfs_customer']['app_user_id'] ?? null);
        $this->assertSame('Test Parent', $contact->metadata['yfs_customer']['display_name'] ?? null);
        $this->assertArrayNotHasKey('children', $contact->metadata['yfs_customer']);
        $this->assertArrayNotHasKey('email', $contact->metadata['yfs_customer']);

        $ambiguous = $this->postJson('/api/voice/elevenlabs/conversation-initiation', [
            'caller_id' => '+15558880008',
        ], [
            'Authorization' => 'Bearer test-elevenlabs-tool-token',
        ])->assertOk()->json();

        $prompt = $ambiguous['conversation_config_override']['agent']['prompt']['prompt'];
        $this->assertStringContainsString('needs_clarification', $prompt);
        $this->assertStringNotContainsString('Other Parent', $prompt);
        $this->assertStringNotContainsString('Twin Parent', $prompt);

        $ambiguousContact = VoiceContact::query()->where('phone_normalized', '+15558880008')->first();
        $this->assertSame('ambiguous', $ambiguousContact?->metadata['yfs_customer']['status'] ?? null);
        $this->assertArrayNotHasKey('app_user_id', $ambiguousContact?->metadata['yfs_customer'] ?? []);
        $this->assertSame(0, $ambiguousContact?->calls_count);
    }

    #[Test]
    public function jfs_failure_does_not_break_initiation_or_language_override(): void
    {
        VoiceAssistantSetting::query()->create([
            'key' => 'general',
            'title' => 'General rules',
            'instructions' => 'Use the app first.',
            'enabled' => true,
            'sort_order' => 1,
        ]);
        VoiceContact::query()->create([
            'phone_normalized' => '+15551230009',
            'phone_display' => '+15551230009',
            'preferred_language' => 'ru',
            'calls_count' => 1,
        ]);
        $this->jfs->configured = false;

        $payload = $this->postJson('/api/voice/elevenlabs/conversation-initiation', [
            'caller_id' => '+15551230009',
        ], [
            'Authorization' => 'Bearer test-elevenlabs-tool-token',
        ])->assertOk()->json();

        $this->assertSame(['type', 'conversation_config_override'], array_keys($payload));
        $this->assertSame('ru', $payload['conversation_config_override']['agent']['language']);
        $this->assertStringContainsString('identity_unavailable', $payload['conversation_config_override']['agent']['prompt']['prompt']);
        $this->assertSame(1, VoiceContact::query()->where('phone_normalized', '+15551230009')->value('calls_count'));
        $this->assertNull(VoiceContact::query()->where('phone_normalized', '+15551230009')->value('metadata'));
    }
}
