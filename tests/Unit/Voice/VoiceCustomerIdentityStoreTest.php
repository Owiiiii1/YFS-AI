<?php

namespace Tests\Unit\Voice;

use App\Models\VoiceContact;
use App\Services\Voice\Identity\CustomerIdentityResult;
use App\Services\Voice\Identity\VoiceCustomerIdentityStore;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\MemoryVoiceContact;
use Tests\TestCase;

class VoiceCustomerIdentityStoreTest extends TestCase
{
    #[Test]
    public function compact_unique_identity_omits_sensitive_fields(): void
    {
        $store = new VoiceCustomerIdentityStore;
        $payload = $store->compact(CustomerIdentityResult::unique('phone', 44, 'Test Parent', 'ru'));

        $this->assertSame('unique', $payload['status']);
        $this->assertSame(44, $payload['app_user_id']);
        $this->assertSame('Test Parent', $payload['display_name']);
        $this->assertSame('phone', $payload['match_method']);
        $this->assertArrayHasKey('matched_at', $payload);
        $this->assertArrayNotHasKey('children', $payload);
        $this->assertArrayNotHasKey('email', $payload);
        $this->assertArrayNotHasKey('phone', $payload);
        $this->assertArrayNotHasKey('tickets', $payload);
        $this->assertArrayNotHasKey('payments', $payload);
        $this->assertArrayNotHasKey('contracts', $payload);
    }

    #[Test]
    public function compact_ambiguous_identity_has_no_customer_selection(): void
    {
        $payload = (new VoiceCustomerIdentityStore)->compact(
            CustomerIdentityResult::ambiguous('phone', 3),
        );

        $this->assertSame('ambiguous', $payload['status']);
        $this->assertSame(3, $payload['candidate_count']);
        $this->assertArrayNotHasKey('app_user_id', $payload);
        $this->assertArrayNotHasKey('display_name', $payload);
    }

    #[Test]
    public function unavailable_result_does_not_write_contact_metadata(): void
    {
        $contact = Mockery::mock(VoiceContact::class);
        $contact->shouldNotReceive('forceFill');
        $contact->shouldNotReceive('save');

        (new VoiceCustomerIdentityStore)->remember(
            $contact,
            CustomerIdentityResult::unavailable('phone'),
        );
        $this->assertTrue(true);
    }

    #[Test]
    public function bind_unique_writes_compact_identity(): void
    {
        $contact = new MemoryVoiceContact;
        $contact->forceFill(['metadata' => []]);

        $outcome = (new VoiceCustomerIdentityStore)->bindUnique(
            $contact,
            CustomerIdentityResult::unique('name', 44, 'Test Parent', 'ru'),
        );

        $this->assertSame(VoiceCustomerIdentityStore::BIND_BOUND, $outcome);
        $stored = $contact->metadata['yfs_customer'];
        $this->assertSame('unique', $stored['status']);
        $this->assertSame(44, $stored['app_user_id']);
        $this->assertSame('Test Parent', $stored['display_name']);
        $this->assertSame('name', $stored['match_method']);
        $this->assertArrayNotHasKey('phone', $stored);
        $this->assertArrayNotHasKey('email', $stored);
        $this->assertArrayNotHasKey('children', $stored);
    }

    #[Test]
    public function bind_unique_does_not_replace_a_different_existing_unique_identity(): void
    {
        $contact = new MemoryVoiceContact;
        $contact->forceFill([
            'metadata' => [
                'yfs_customer' => [
                    'status' => 'unique',
                    'app_user_id' => 10,
                    'display_name' => 'Existing Parent',
                    'match_method' => 'phone',
                    'matched_at' => '2026-01-01T00:00:00Z',
                ],
            ],
        ]);

        $outcome = (new VoiceCustomerIdentityStore)->bindUnique(
            $contact,
            CustomerIdentityResult::unique('name', 99, 'Other Parent', 'en'),
        );

        $this->assertSame(VoiceCustomerIdentityStore::BIND_PRESERVED, $outcome);
        $this->assertSame(10, $contact->metadata['yfs_customer']['app_user_id']);
        $this->assertSame('Existing Parent', $contact->metadata['yfs_customer']['display_name']);
    }

    #[Test]
    public function bind_unique_can_replace_when_caller_is_explicitly_on_behalf_of_another(): void
    {
        $contact = new MemoryVoiceContact;
        $contact->forceFill([
            'metadata' => [
                'yfs_customer' => [
                    'status' => 'unique',
                    'app_user_id' => 10,
                    'display_name' => 'Existing Parent',
                    'match_method' => 'phone',
                    'matched_at' => '2026-01-01T00:00:00Z',
                ],
            ],
        ]);

        $outcome = (new VoiceCustomerIdentityStore)->bindUnique(
            $contact,
            CustomerIdentityResult::unique('name', 99, 'Other Parent', 'en'),
            true,
        );

        $this->assertSame(VoiceCustomerIdentityStore::BIND_BOUND, $outcome);
        $this->assertSame(99, $contact->metadata['yfs_customer']['app_user_id']);
        $this->assertSame('Other Parent', $contact->metadata['yfs_customer']['display_name']);
    }

    #[Test]
    public function bind_unique_skips_non_unique_results(): void
    {
        $contact = new MemoryVoiceContact;
        $contact->forceFill(['metadata' => ['keep' => true]]);
        $store = new VoiceCustomerIdentityStore;

        $this->assertSame(
            VoiceCustomerIdentityStore::BIND_SKIPPED,
            $store->bindUnique($contact, CustomerIdentityResult::ambiguous('name', 2)),
        );
        $this->assertSame(
            VoiceCustomerIdentityStore::BIND_SKIPPED,
            $store->bindUnique($contact, CustomerIdentityResult::notFound('name')),
        );
        $this->assertSame(['keep' => true], $contact->metadata);
    }
}
