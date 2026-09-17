<?php

namespace Tests\Feature;

use App\Models\VoiceAssistantSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VoiceContextEndpointDatabaseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite is required for isolated feature tests.');
        }

        parent::setUp();

        config(['services.elevenlabs.tool_token' => 'test-elevenlabs-tool-token']);
        config(['services.voice_runtime.internal_token' => 'test-voice-runtime-token']);
    }

    #[Test]
    public function valid_auth_returns_prompt_version_and_timestamp_without_secrets(): void
    {
        VoiceAssistantSetting::query()->create([
            'key' => 'general',
            'title' => 'General rules',
            'instructions' => 'Use the app first.',
            'enabled' => true,
            'sort_order' => 1,
        ]);

        $response = $this->postJson('/api/voice/context', [], [
            'Authorization' => 'Bearer test-elevenlabs-tool-token',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['prompt', 'version', 'generated_at'])
            ->assertDontSee('test-elevenlabs-tool-token', false)
            ->assertDontSee('test-voice-runtime-token', false);

        $payload = $response->json();
        $this->assertSame(['prompt', 'version', 'generated_at'], array_keys($payload));
        $this->assertStringContainsString('Use the app first.', $payload['prompt']);
        $this->assertStringContainsString('## General rules', $payload['prompt']);
        $this->assertStringContainsString('Customer Support Voice Assistant', $payload['prompt']);
        $this->assertStringContainsString('get_public_shows', $payload['prompt']);
        $this->assertStringContainsString('get_show_brands', $payload['prompt']);
        $this->assertStringContainsString('E. CALLER IDENTITY', $payload['prompt']);
        $this->assertStringContainsString('resolve_customer_identity', $payload['prompt']);
        $this->assertStringContainsString('start_extended_identity_search', $payload['prompt']);
        $this->assertStringContainsString('get_customer_context', $payload['prompt']);
        $this->assertMatchesRegularExpression('/^v7-[a-f0-9]{64}$/', $payload['version']);
        $this->assertNotFalse(strtotime($payload['generated_at']));
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

        $response = $this->postJson('/api/voice/context', [], [
            'Authorization' => 'Bearer test-elevenlabs-tool-token',
        ])->assertOk();

        $prompt = $response->json('prompt');
        $this->assertStringContainsString('General body.', $prompt);
        $this->assertStringContainsString('Payment body.', $prompt);
        $this->assertStringNotContainsString('Hidden backstage body.', $prompt);
        $this->assertTrue(strpos($prompt, '## General rules') < strpos($prompt, '## Payments'));
    }

    #[Test]
    public function changing_instructions_changes_version_and_identical_settings_do_not(): void
    {
        $section = VoiceAssistantSetting::query()->create([
            'key' => 'general',
            'title' => 'General rules',
            'instructions' => 'Original policy.',
            'enabled' => true,
            'sort_order' => 1,
        ]);

        $first = $this->postJson('/api/voice/context', [], [
            'Authorization' => 'Bearer test-elevenlabs-tool-token',
        ])->assertOk();

        $second = $this->postJson('/api/voice/context', [], [
            'Authorization' => 'Bearer test-elevenlabs-tool-token',
        ])->assertOk();

        $this->assertSame($first->json('version'), $second->json('version'));
        $this->assertSame($first->json('prompt'), $second->json('prompt'));

        $section->forceFill(['instructions' => 'Edited policy.'])->save();

        $third = $this->postJson('/api/voice/context', [], [
            'Authorization' => 'Bearer test-elevenlabs-tool-token',
        ])->assertOk();

        $this->assertNotSame($first->json('version'), $third->json('version'));
        $this->assertStringContainsString('Edited policy.', $third->json('prompt'));
        $this->assertStringNotContainsString('Original policy.', $third->json('prompt'));
    }
}
