<?php

namespace Tests\Unit\Voice;

use App\Models\VoiceIdentitySearch;
use App\Services\Voice\Identity\ExtendedVoiceIdentitySearchService;
use App\Services\Voice\Identity\VoiceContactSessionResolver;
use App\Services\Voice\Identity\VoiceCustomerIdentityStore;
use App\Services\Voice\Tools\GetExtendedIdentitySearchStatusVoiceTool;
use App\Services\Voice\Tools\StartExtendedIdentitySearchVoiceTool;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\MemoryVoiceContact;
use Tests\TestCase;

class ExtendedVoiceIdentitySearchTest extends TestCase
{
    #[Test]
    public function public_status_maps_running_to_searching_without_candidates(): void
    {
        $service = new ExtendedVoiceIdentitySearchService(new VoiceCustomerIdentityStore);
        $search = new VoiceIdentitySearch([
            'status' => VoiceIdentitySearch::RUNNING,
            'result_metadata' => ['status' => 'running'],
        ]);

        $payload = $service->publicStatus($search);
        $encoded = json_encode($payload) ?: '';

        $this->assertSame('searching', $payload['status']);
        $this->assertSame('continue_conversation', $payload['next_action']);
        $this->assertArrayNotHasKey('customer', $payload);
        $this->assertStringNotContainsString('candidate', $encoded);
        $this->assertStringNotContainsString('@', $encoded);
    }

    #[Test]
    public function public_unique_status_only_includes_display_name(): void
    {
        $service = new ExtendedVoiceIdentitySearchService(new VoiceCustomerIdentityStore);
        $search = new VoiceIdentitySearch([
            'status' => VoiceIdentitySearch::UNIQUE,
            'result_metadata' => [
                'status' => 'unique',
                'display_name' => 'Olga Petrova',
                'app_user_id' => 13,
            ],
        ]);

        $payload = $service->publicStatus($search);
        $encoded = json_encode($payload) ?: '';

        $this->assertSame('unique', $payload['status']);
        $this->assertSame(['display_name' => 'Olga Petrova'], $payload['customer']);
        $this->assertStringNotContainsString('13', $encoded);
        $this->assertStringNotContainsString('app_user', $encoded);
    }

    #[Test]
    public function public_ambiguous_and_not_found_have_no_customer(): void
    {
        $service = new ExtendedVoiceIdentitySearchService(new VoiceCustomerIdentityStore);

        $ambiguous = $service->publicStatus(new VoiceIdentitySearch(['status' => VoiceIdentitySearch::AMBIGUOUS]));
        $missing = $service->publicStatus(new VoiceIdentitySearch(['status' => VoiceIdentitySearch::NOT_FOUND]));
        $failed = $service->publicStatus(new VoiceIdentitySearch(['status' => VoiceIdentitySearch::FAILED]));

        $this->assertSame('ambiguous', $ambiguous['status']);
        $this->assertArrayNotHasKey('customer', $ambiguous);
        $this->assertSame('not_found', $missing['status']);
        $this->assertSame('failed', $failed['status']);
    }

    #[Test]
    public function start_tool_returns_searching_without_pii(): void
    {
        $contact = new MemoryVoiceContact;
        $contact->id = 5;
        $contact->forceFill(['metadata' => []]);

        $sessions = Mockery::mock(VoiceContactSessionResolver::class);
        $sessions->shouldReceive('findTrusted')->andReturn($contact);

        $search = new VoiceIdentitySearch([
            'status' => VoiceIdentitySearch::PENDING,
            'hints' => ['has_name' => true],
        ]);
        $service = Mockery::mock(ExtendedVoiceIdentitySearchService::class);
        $service->shouldReceive('start')->once()->andReturn($search);
        $service->shouldReceive('publicStatus')->once()->andReturn([
            'status' => 'searching',
            'next_action' => 'continue_conversation',
        ]);

        $payload = (new StartExtendedIdentitySearchVoiceTool(
            $service,
            $sessions,
            new VoiceCustomerIdentityStore,
        ))->execute([
            'name' => 'Olga Petrova',
            'system__caller_id' => '+15552220002',
        ]);

        $this->assertSame('searching', $payload['status']);
        $this->assertSame('start_extended_identity_search', $payload['tool']);
        $this->assertArrayNotHasKey('customer', $payload);
        $encoded = json_encode($payload) ?: '';
        $this->assertStringNotContainsString('Olga', $encoded);
        $this->assertStringNotContainsString('555', $encoded);
    }

    #[Test]
    public function start_tool_does_not_replace_existing_unique_identity(): void
    {
        $contact = new MemoryVoiceContact;
        $contact->forceFill([
            'metadata' => [
                'yfs_customer' => [
                    'status' => 'unique',
                    'app_user_id' => 13,
                    'display_name' => 'Olga Petrova',
                    'match_method' => 'phone',
                ],
            ],
        ]);
        $sessions = Mockery::mock(VoiceContactSessionResolver::class);
        $sessions->shouldReceive('findTrusted')->andReturn($contact);
        $service = Mockery::mock(ExtendedVoiceIdentitySearchService::class);
        $service->shouldNotReceive('start');

        $payload = (new StartExtendedIdentitySearchVoiceTool(
            $service,
            $sessions,
            new VoiceCustomerIdentityStore,
        ))->execute(['system__caller_id' => '+15552220002']);

        $this->assertSame('unique', $payload['status']);
        $this->assertSame('already_identified', $payload['next_action']);
        $this->assertSame('Olga Petrova', $payload['customer']['display_name']);
    }

    #[Test]
    public function status_tool_returns_unique_without_crm_payload(): void
    {
        $contact = new MemoryVoiceContact;
        $contact->forceFill(['metadata' => []]);
        $sessions = Mockery::mock(VoiceContactSessionResolver::class);
        $sessions->shouldReceive('findTrusted')->andReturn($contact);
        $service = Mockery::mock(ExtendedVoiceIdentitySearchService::class);
        $service->shouldReceive('latestFor')->andReturn(new VoiceIdentitySearch(['status' => VoiceIdentitySearch::UNIQUE]));
        $service->shouldReceive('publicStatus')->andReturn([
            'status' => 'unique',
            'next_action' => 'identified',
            'customer' => ['display_name' => 'Olga Petrova'],
        ]);

        $payload = (new GetExtendedIdentitySearchStatusVoiceTool($service, $sessions))->execute([
            'system__caller_id' => '+15552220002',
        ]);

        $this->assertSame('unique', $payload['status']);
        $this->assertSame(['display_name'], array_keys($payload['customer']));
        $this->assertStringNotContainsString('EMAIL', json_encode($payload) ?: '');
    }
}
