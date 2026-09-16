<?php

namespace Tests\Feature;

use App\Models\VoiceContact;
use App\Models\VoiceIdentitySearch;
use App\Services\Bitrix\BitrixYfsLinker;
use App\Services\Jfs\JfsReadService;
use App\Services\Voice\Identity\CustomerIdentityResolver;
use App\Services\Voice\Identity\CustomerIdentityResult;
use App\Services\Voice\Identity\ExtendedVoiceIdentitySearchRunner;
use App\Services\Voice\Identity\VoiceCustomerIdentityStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeBitrixIdentityGateway;
use Tests\Support\FakeJfsReadService;
use Tests\TestCase;

class ExtendedVoiceIdentitySearchDatabaseTest extends TestCase
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
    public function runner_unique_binds_voice_contact_and_does_not_overwrite_other_identity(): void
    {
        $jfs = new FakeJfsReadService;
        $jfs->clients = [[
            'id' => 13,
            'name' => 'Olga Petrova',
            'language' => 'uk',
            'phone' => '+1-555-222-0002',
            'email' => 'olga@example.com',
            'children' => ['Mia'],
        ]];
        $bitrix = new FakeBitrixIdentityGateway;
        $bitrix->nameToContactIds['olga petrova'] = [70];
        $bitrix->contactEmails[70] = ['olga@example.com'];
        $store = new VoiceCustomerIdentityStore;
        $resolver = new CustomerIdentityResolver($jfs, $bitrix, new BitrixYfsLinker($jfs));

        $contact = VoiceContact::query()->create([
            'phone_normalized' => '+15550000000',
            'phone_display' => '+15550000000',
            'metadata' => [],
        ]);
        $search = VoiceIdentitySearch::query()->create([
            'voice_contact_id' => $contact->id,
            'status' => VoiceIdentitySearch::PENDING,
            'hints' => ['name' => 'Olga Petrova', 'has_name' => true],
            'started_at' => now(),
            'expires_at' => now()->addMinutes(30),
        ]);

        (new ExtendedVoiceIdentitySearchRunner($resolver, new BitrixYfsLinker($jfs), $store))->run($search->id);

        $search->refresh();
        $contact->refresh();
        $this->assertSame(VoiceIdentitySearch::UNIQUE, $search->status);
        $this->assertSame(13, $contact->metadata['yfs_customer']['app_user_id']);
        $this->assertSame('Olga Petrova', $contact->metadata['yfs_customer']['display_name']);
        $this->assertArrayNotHasKey('email', $contact->metadata['yfs_customer']);
        $this->assertArrayNotHasKey('phone', $contact->metadata['yfs_customer']);

        $other = CustomerIdentityResult::unique('name', 99, 'Other Parent', 'en');
        $this->assertSame(VoiceCustomerIdentityStore::BIND_PRESERVED, $store->bindUnique($contact->fresh(), $other));
        $this->assertSame(13, $contact->fresh()->metadata['yfs_customer']['app_user_id']);
    }

    #[Test]
    public function runner_ambiguous_and_not_found_do_not_bind(): void
    {
        $jfs = new FakeJfsReadService;
        $bitrix = new FakeBitrixIdentityGateway;
        $bitrix->nameToContactIds['ivanova'] = [1, 2];
        $store = new VoiceCustomerIdentityStore;
        $resolver = new CustomerIdentityResolver($jfs, $bitrix, new BitrixYfsLinker($jfs));
        $contact = VoiceContact::query()->create([
            'phone_normalized' => '+15550001111',
            'metadata' => [],
        ]);
        $search = VoiceIdentitySearch::query()->create([
            'voice_contact_id' => $contact->id,
            'status' => VoiceIdentitySearch::PENDING,
            'hints' => ['name' => 'Ivanova', 'has_name' => true],
            'expires_at' => now()->addMinutes(30),
        ]);

        (new ExtendedVoiceIdentitySearchRunner($resolver, new BitrixYfsLinker($jfs), $store))->run($search->id);

        $this->assertSame(VoiceIdentitySearch::AMBIGUOUS, $search->fresh()->status);
        $this->assertTrue(blank($contact->fresh()->metadata['yfs_customer'] ?? null));
    }

    #[Test]
    public function runner_failed_does_not_bind(): void
    {
        $resolver = \Mockery::mock(CustomerIdentityResolver::class);
        $resolver->shouldReceive('resolveByPhoneFast')->andThrow(new \RuntimeException('lookup failed'));
        $store = new VoiceCustomerIdentityStore;
        $contact = VoiceContact::query()->create([
            'phone_normalized' => '+15550002222',
            'metadata' => [],
        ]);
        $search = VoiceIdentitySearch::query()->create([
            'voice_contact_id' => $contact->id,
            'status' => VoiceIdentitySearch::PENDING,
            'hints' => ['has_name' => false],
            'expires_at' => now()->addMinutes(30),
        ]);

        (new ExtendedVoiceIdentitySearchRunner(
            $resolver,
            new BitrixYfsLinker(new FakeJfsReadService),
            $store,
        ))->run($search->id);

        $this->assertSame(VoiceIdentitySearch::FAILED, $search->fresh()->status);
        $this->assertTrue(blank($contact->fresh()->metadata['yfs_customer'] ?? null));
    }
}
