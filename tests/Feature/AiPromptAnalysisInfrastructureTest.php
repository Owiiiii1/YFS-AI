<?php

namespace Tests\Feature;

use App\Models\AiProviderSetting;
use App\Models\AiRoleConnection;
use App\Models\BotPromptRevision;
use App\Models\BotSetting;
use App\Services\Ai\AiConnectionResolver;
use App\Services\Bot\BotPromptApplyService;
use App\Support\DefaultBotPromptConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AiPromptAnalysisInfrastructureTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function analysis_connection_inherits_gemini_key_without_changing_runtime_model(): void
    {
        AiProviderSetting::query()->create([
            'provider' => 'gemini',
            'label' => 'Gemini',
            'api_key' => 'shared-secret',
            'is_connected' => true,
            'is_active' => true,
            'active_model' => 'gemini-3.1-flash-lite',
        ]);
        AiRoleConnection::query()->create([
            'role' => AiRoleConnection::ROLE_PROMPT_ANALYSIS,
            'provider' => 'gemini',
            'key_source_provider' => 'gemini',
            'active_model' => 'gemini-3.5-flash',
            'is_connected' => true,
        ]);

        $resolver = app(AiConnectionResolver::class);

        $this->assertSame('gemini-3.1-flash-lite', $resolver->resolve()['model']);
        $this->assertSame('gemini-3.5-flash', $resolver->resolve('prompt_analysis')['model']);
        $this->assertSame('shared-secret', $resolver->resolve('prompt_analysis')['api_key']);
    }

    #[Test]
    public function prompt_apply_is_atomic_revisioned_and_rejects_lost_updates(): void
    {
        $config = DefaultBotPromptConfig::make();
        BotSetting::query()->create([
            'bot_enabled' => true,
            'prompt_config' => $config,
            'prompt_revision' => 1,
            'structured_prompts_ready' => true,
        ]);
        $changed = $config;
        foreach ($changed['topics'] as $index => $topic) {
            if (($topic['id'] ?? null) === 'language') {
                $changed['topics'][$index]['text'] .= "\nPreserve the detected language.";
                break;
            }
        }

        $updated = app(BotPromptApplyService::class)->apply($changed, 1, null, 'test');

        $this->assertSame(2, $updated->prompt_revision);
        $this->assertDatabaseHas('bot_prompt_revisions', ['revision' => 1, 'reason' => 'test']);

        $this->expectException(ValidationException::class);
        app(BotPromptApplyService::class)->apply($config, 1, null, 'stale');
        $this->assertSame(1, BotPromptRevision::query()->count());
    }
}
