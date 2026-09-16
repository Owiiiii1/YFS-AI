<?php

namespace Tests\Unit\Voice;

use App\Models\VoiceContact;
use App\Services\Voice\Contacts\VoiceContactDirectory;
use App\Services\Voice\Identity\CustomerIdentityResolver;
use App\Services\Voice\Identity\CustomerIdentityResult;
use App\Services\Voice\Identity\VoiceConversationInitiationService;
use App\Services\Voice\Identity\VoiceCustomerIdentityStore;
use App\Services\Voice\Prompt\VoiceAssistantPromptBuilder;
use App\Services\Voice\Prompt\VoiceAssistantRuntimePrompt;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeJfsReadService;
use Tests\TestCase;

class VoiceConversationInitiationServiceTest extends TestCase
{
    #[Test]
    public function unique_phone_keeps_initiation_contract_and_does_not_put_ids_in_prompt(): void
    {
        $payload = $this->payloadFor(
            CustomerIdentityResult::unique('phone', 99, 'Test Parent', 'ru'),
        );

        $this->assertSame('conversation_initiation_client_data', $payload['type']);
        $this->assertSame(['type', 'conversation_config_override'], array_keys($payload));
        $prompt = $payload['conversation_config_override']['agent']['prompt']['prompt'];
        $this->assertStringContainsString('Test Parent', $prompt);
        $this->assertStringContainsString('Status: identified.', $prompt);
        $this->assertStringContainsString('E. CALLER IDENTITY', $prompt);
        $this->assertStringContainsString('Use the app first.', $prompt);
        $this->assertStringNotContainsString('99', $prompt);
        $this->assertSame('ru', $payload['conversation_config_override']['agent']['language']);
        $this->assertArrayNotHasKey('dynamic_variables', $payload);
        $this->assertArrayNotHasKey('llm', $payload['conversation_config_override']['agent']['prompt']);
    }

    #[Test]
    public function stored_preferred_language_wins_over_jfs_language(): void
    {
        $payload = $this->payloadFor(
            CustomerIdentityResult::unique('phone', 99, 'Test Parent', 'ru'),
            preferredLanguage: 'uk',
        );

        $this->assertSame('uk', $payload['conversation_config_override']['agent']['language']);
    }

    #[Test]
    public function ambiguous_phone_does_not_name_or_select_a_customer(): void
    {
        $payload = $this->payloadFor(CustomerIdentityResult::ambiguous('phone', 2));
        $prompt = $payload['conversation_config_override']['agent']['prompt']['prompt'];

        $this->assertSame(['type', 'conversation_config_override'], array_keys($payload));
        $this->assertStringContainsString('needs_clarification', $prompt);
        $this->assertStringNotContainsString('Status: identified.', $prompt);
        $this->assertArrayNotHasKey('language', $payload['conversation_config_override']['agent']);
    }

    #[Test]
    public function unknown_phone_continues_with_unknown_caller_context(): void
    {
        $payload = $this->payloadFor(CustomerIdentityResult::notFound('phone'));
        $prompt = $payload['conversation_config_override']['agent']['prompt']['prompt'];

        $this->assertStringContainsString('Status: unknown.', $prompt);
        $this->assertStringContainsString('get_public_shows', $prompt);
        $this->assertSame(['type', 'conversation_config_override'], array_keys($payload));
    }

    #[Test]
    public function jfs_failure_does_not_break_initiation(): void
    {
        $payload = $this->payloadFor(CustomerIdentityResult::unavailable('phone'));
        $prompt = $payload['conversation_config_override']['agent']['prompt']['prompt'];

        $this->assertSame('conversation_initiation_client_data', $payload['type']);
        $this->assertStringContainsString('identity_unavailable', $prompt);
        $this->assertStringContainsString('Use the app first.', $prompt);
    }

    #[Test]
    public function conversation_id_is_remembered_on_the_current_contact(): void
    {
        $identity = CustomerIdentityResult::unique('phone', 99, 'Test Parent', 'ru');
        $contact = Mockery::mock(VoiceContact::class);
        $contact->shouldReceive('getAttribute')->with('preferred_language')->andReturn(null);
        $contact->shouldReceive('fresh')->andReturnSelf();
        $contact->shouldIgnoreMissing();

        $directory = Mockery::mock(VoiceContactDirectory::class);
        $directory->shouldReceive('findOrCreateFromCallerId')->once()->andReturn($contact);
        $directory->shouldReceive('rememberConversationId')->once()->with($contact, 'conv_live');

        $resolver = Mockery::mock(CustomerIdentityResolver::class, [new FakeJfsReadService])->makePartial();
        $resolver->shouldReceive('resolveByPhone')->once()->andReturn($identity);

        $store = Mockery::mock(VoiceCustomerIdentityStore::class)->makePartial();
        $store->shouldReceive('remember')->once();

        $builder = Mockery::mock(VoiceAssistantPromptBuilder::class)->makePartial();
        $builder->shouldReceive('build')->once()->andReturn(
            (new VoiceAssistantPromptBuilder)->assemble([[
                'key' => 'general',
                'title' => 'General rules',
                'instructions' => 'Use the app first.',
                'sort_order' => 1,
            ]], null, $identity),
        );

        $payload = (new VoiceConversationInitiationService(
            $directory,
            $resolver,
            $store,
            $builder,
        ))->payload('+15551234567', 'conv_live');

        $this->assertSame('conversation_initiation_client_data', $payload['type']);
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadFor(CustomerIdentityResult $identity, ?string $preferredLanguage = null): array
    {
        $contact = Mockery::mock(VoiceContact::class);
        $contact->shouldReceive('getAttribute')->with('preferred_language')->andReturn($preferredLanguage);
        $contact->shouldReceive('fresh')->andReturnSelf();
        $contact->shouldIgnoreMissing();

        $directory = Mockery::mock(VoiceContactDirectory::class);
        $directory->shouldReceive('findOrCreateFromCallerId')->once()->andReturn($contact);
        $directory->shouldNotReceive('rememberConversationId');

        $resolver = Mockery::mock(CustomerIdentityResolver::class, [new FakeJfsReadService])->makePartial();
        $resolver->shouldReceive('resolveByPhone')->once()->andReturn($identity);

        $store = Mockery::mock(VoiceCustomerIdentityStore::class)->makePartial();
        $store->shouldReceive('remember')->once();

        $builder = Mockery::mock(VoiceAssistantPromptBuilder::class)->makePartial();
        $builder->shouldReceive('build')->once()->andReturnUsing(
            function ($generatedAt = null, $passedIdentity = null) use ($identity): VoiceAssistantRuntimePrompt {
                return (new VoiceAssistantPromptBuilder)->assemble([[
                    'key' => 'general',
                    'title' => 'General rules',
                    'instructions' => 'Use the app first.',
                    'sort_order' => 1,
                ]], null, $passedIdentity ?? $identity);
            }
        );

        return (new VoiceConversationInitiationService(
            $directory,
            $resolver,
            $store,
            $builder,
        ))->payload('+15551234567');
    }
}
