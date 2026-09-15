<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VoiceAssistantSetting;
use App\Support\VoiceAssistantSettingCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VoiceBotSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite is required for isolated feature tests.');
        }

        parent::setUp();
    }

    #[Test]
    public function guest_cannot_access_bot_settings(): void
    {
        $this->get(route('call-center.bot-settings'))
            ->assertRedirect(route('login'));

        $this->patch(route('call-center.bot-settings.update', 'general'), [
            'instructions' => 'nope',
            'enabled' => true,
        ])->assertRedirect(route('login'));
    }

    #[Test]
    public function admin_can_open_bot_settings_with_seeded_sections(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('call-center.bot-settings'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('CallCenter/BotSettings')
                ->where('tab', 'general')
                ->has('sections', 13)
                ->where('sections.0.key', 'general')
                ->where('sections.10.key', 'service_tiers')
                ->where('sections.11.key', 'sales_support')
                ->where('sections.12.key', 'escalation')
                ->where('sections.10.instructions', fn ($text) => is_string($text)
                    && str_contains($text, 'BASIC — Self-Service First')
                    && str_contains($text, 'PREMIUM — Self-Service + Customer Support')
                    && str_contains($text, 'VIP — Priority Personal Support'))
                ->where('sections.11.instructions', fn ($text) => is_string($text)
                    && str_contains($text, 'SALE → CONTRACT → CUSTOMER SUPPORT')
                    && str_contains($text, 'CUSTOMER SUPPORT → SALES'))
            );

        $this->assertSame(count(VoiceAssistantSettingCatalog::KEYS), VoiceAssistantSetting::query()->count());
    }

    #[Test]
    public function admin_can_save_and_persist_section_changes(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('call-center.bot-settings', ['tab' => 'service_tiers']))
            ->assertOk();

        $this->actingAs($user)
            ->patch(route('call-center.bot-settings.update', 'service_tiers'), [
                'instructions' => "BASIC:\nSelf-Service First.\nApp / Help Center → Customer Support request → callback.",
                'enabled' => 0,
            ])
            ->assertRedirect(route('call-center.bot-settings', ['tab' => 'service_tiers']));

        $section = VoiceAssistantSetting::query()->where('key', 'service_tiers')->first();
        $this->assertNotNull($section);
        $this->assertFalse((bool) $section->enabled);
        $this->assertStringContainsString('Self-Service First', (string) $section->instructions);

        $this->actingAs($user)
            ->get(route('call-center.bot-settings', ['tab' => 'service_tiers']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('CallCenter/BotSettings')
                ->where('tab', 'service_tiers')
                ->where('sections', fn ($sections) => collect($sections)->firstWhere('key', 'service_tiers')['enabled'] === false
                    && str_contains((string) collect($sections)->firstWhere('key', 'service_tiers')['instructions'], 'Self-Service First'))
            );
    }

    #[Test]
    public function unknown_section_cannot_be_saved(): void
    {
        $this->actingAs(User::factory()->create())
            ->patch(route('call-center.bot-settings.update', 'not-a-section'), [
                'instructions' => 'x',
                'enabled' => true,
            ])
            ->assertNotFound();
    }

    #[Test]
    public function voice_assistant_placeholder_page_is_unchanged(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('call-center.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('CallCenter/Index')
                ->missing('sections')
            );

        $this->assertSame(0, VoiceAssistantSetting::query()->count());
    }
}
