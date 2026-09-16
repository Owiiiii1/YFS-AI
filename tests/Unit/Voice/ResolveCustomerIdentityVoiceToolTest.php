<?php

namespace Tests\Unit\Voice;

use App\Services\Voice\Identity\CustomerIdentityResolver;
use App\Services\Voice\Identity\VoiceContactSessionResolver;
use App\Services\Voice\Identity\VoiceCustomerIdentityStore;
use App\Services\Voice\Tools\ResolveCustomerIdentityVoiceTool;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeJfsReadService;
use Tests\Support\MemoryVoiceContact;
use Tests\TestCase;

class ResolveCustomerIdentityVoiceToolTest extends TestCase
{
    private FakeJfsReadService $jfs;

    private VoiceCustomerIdentityStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->jfs = new FakeJfsReadService;
        $this->jfs->clients = [
            [
                'id' => 11,
                'name' => 'Anna Ivanova',
                'language' => 'ru',
                'phone' => '+1-555-111-0001',
                'children' => ['Mia'],
            ],
            [
                'id' => 12,
                'name' => 'Petr Petrov',
                'language' => 'en',
                'phone' => '+1-555-111-0001',
                'children' => ['Leo'],
            ],
            [
                'id' => 13,
                'name' => 'Olga Petrova',
                'language' => 'uk',
                'phone' => '+1-555-222-0002',
                'children' => ['Mia'],
            ],
            [
                'id' => 14,
                'name' => 'Maria Ivanova',
                'language' => 'en',
                'phone' => '+1-555-444-0004',
                'children' => ['Sam'],
            ],
        ];
        $this->store = new VoiceCustomerIdentityStore;
    }

    #[Test]
    public function unique_name_identifies_without_sensitive_fields(): void
    {
        $payload = $this->tool()->execute(['name' => 'Olga Petrova']);

        $this->assertSame([
            'ok' => true,
            'tool' => 'resolve_customer_identity',
            'status' => 'unique',
            'next_action' => 'identified',
            'customer' => ['display_name' => 'Olga Petrova'],
        ], $payload);
        $this->assertSame(['ok', 'tool', 'status', 'next_action', 'customer'], array_keys($payload));
        $this->assertSame(['display_name'], array_keys($payload['customer']));
        $encoded = json_encode($payload) ?: '';
        $this->assertStringNotContainsString('555', $encoded);
        $this->assertStringNotContainsString('@', $encoded);
        $this->assertStringNotContainsString('"id"', $encoded);
        $this->assertStringNotContainsString('app_user', $encoded);
        $this->assertStringNotContainsString('Mia', $encoded);
    }

    #[Test]
    public function ambiguous_name_asks_for_child_and_does_not_list_candidates(): void
    {
        $payload = $this->tool()->execute(['name' => 'Ivanova']);

        $this->assertTrue($payload['ok']);
        $this->assertSame('ambiguous', $payload['status']);
        $this->assertSame('ask_child_name', $payload['next_action']);
        $this->assertArrayNotHasKey('customer', $payload);
        $encoded = json_encode($payload) ?: '';
        $this->assertStringNotContainsString('Anna', $encoded);
        $this->assertStringNotContainsString('Maria', $encoded);
        $this->assertStringNotContainsString('candidates', $encoded);
    }

    #[Test]
    public function unknown_name_is_not_found(): void
    {
        $payload = $this->tool()->execute(['name' => 'Nobody Here']);

        $this->assertSame('not_found', $payload['status']);
        $this->assertSame('ask_again_or_continue_without_identity', $payload['next_action']);
        $this->assertArrayNotHasKey('customer', $payload);
    }

    #[Test]
    public function name_plus_child_can_resolve_ambiguity(): void
    {
        $payload = $this->tool()->execute([
            'name' => 'Ivanova',
            'child_name' => 'Mia',
        ]);

        $this->assertSame('unique', $payload['status']);
        $this->assertSame('identified', $payload['next_action']);
        $this->assertSame('Anna Ivanova', $payload['customer']['display_name']);
        $this->assertStringNotContainsString('Mia', json_encode($payload) ?: '');
    }

    #[Test]
    public function name_plus_child_still_ambiguous_asks_for_additional_identifier(): void
    {
        $this->jfs->clients[] = [
            'id' => 21,
            'name' => 'Anna Ivanova',
            'language' => 'en',
            'phone' => '+1-555-999-0009',
            'children' => ['Mia'],
        ];

        $payload = $this->tool()->execute([
            'name' => 'Ivanova',
            'child_name' => 'Mia',
        ]);

        $this->assertSame('ambiguous', $payload['status']);
        $this->assertSame('ask_additional_identifier', $payload['next_action']);
        $this->assertArrayNotHasKey('customer', $payload);
    }

    #[Test]
    public function source_unavailable_does_not_invent_identity(): void
    {
        $this->jfs->configured = false;

        $payload = $this->tool()->execute(['name' => 'Olga Petrova']);

        $this->assertFalse($payload['ok']);
        $this->assertSame('source_unavailable', $payload['status']);
        $this->assertSame('continue_without_identity', $payload['next_action']);
        $this->assertArrayNotHasKey('customer', $payload);
    }

    #[Test]
    public function missing_name_and_child_is_invalid_without_jfs_lookup(): void
    {
        $payload = $this->tool()->execute([]);

        $this->assertFalse($payload['ok']);
        $this->assertSame('invalid_request', $payload['status']);
        $this->assertSame('ask_name', $payload['next_action']);
        $this->assertSame(0, $this->jfs->findClientsByNameCalls);
        $this->assertSame(0, $this->jfs->findClientsByChildNameCalls);
    }

    #[Test]
    public function unique_result_binds_when_trusted_session_exists(): void
    {
        $contact = new MemoryVoiceContact;
        $contact->forceFill(['metadata' => []]);

        $payload = $this->tool($contact)->execute([
            'name' => 'Olga Petrova',
            'system__caller_id' => '+15552220002',
        ]);

        $this->assertSame('unique', $payload['status']);
        $this->assertSame('identified', $payload['next_action']);
        $this->assertSame('unique', $contact->metadata['yfs_customer']['status']);
        $this->assertSame(13, $contact->metadata['yfs_customer']['app_user_id']);
        $this->assertSame('Olga Petrova', $contact->metadata['yfs_customer']['display_name']);
        $this->assertSame('name', $contact->metadata['yfs_customer']['match_method']);
        $this->assertArrayNotHasKey('phone', $contact->metadata['yfs_customer']);
    }

    #[Test]
    public function unique_lookup_without_session_still_returns_identity_but_does_not_persist(): void
    {
        $payload = $this->tool()->execute(['name' => 'Olga Petrova']);

        $this->assertSame('unique', $payload['status']);
        $this->assertSame('Olga Petrova', $payload['customer']['display_name']);
    }

    #[Test]
    public function ambiguous_does_not_bind_identity(): void
    {
        $contact = new MemoryVoiceContact;
        $contact->forceFill(['metadata' => ['keep' => true]]);

        $payload = $this->tool($contact)->execute([
            'name' => 'Ivanova',
            'system__caller_id' => '+15551110001',
        ]);

        $this->assertSame('ambiguous', $payload['status']);
        $this->assertSame(['keep' => true], $contact->metadata);
    }

    #[Test]
    public function not_found_does_not_create_a_fake_identity(): void
    {
        $contact = new MemoryVoiceContact;
        $contact->forceFill(['metadata' => []]);

        $payload = $this->tool($contact)->execute([
            'name' => 'Nobody Here',
            'system__caller_id' => '+15559990000',
        ]);

        $this->assertSame('not_found', $payload['status']);
        $this->assertSame([], $contact->metadata);
        $this->assertArrayNotHasKey('customer', $payload);
    }

    #[Test]
    public function source_unavailable_does_not_destroy_existing_unique_identity(): void
    {
        $this->jfs->configured = false;
        $contact = new MemoryVoiceContact;
        $contact->forceFill([
            'metadata' => [
                'yfs_customer' => [
                    'status' => 'unique',
                    'app_user_id' => 13,
                    'display_name' => 'Olga Petrova',
                    'match_method' => 'phone',
                    'matched_at' => '2026-01-01T00:00:00Z',
                ],
            ],
        ]);

        $payload = $this->tool($contact)->execute([
            'name' => 'Olga Petrova',
            'system__caller_id' => '+15552220002',
        ]);

        $this->assertFalse($payload['ok']);
        $this->assertSame('source_unavailable', $payload['status']);
        $this->assertSame(13, $contact->metadata['yfs_customer']['app_user_id']);
        $this->assertSame('Olga Petrova', $contact->metadata['yfs_customer']['display_name']);
    }

    #[Test]
    public function existing_unique_caller_identity_is_not_replaced_by_another_spoken_match(): void
    {
        $contact = new MemoryVoiceContact;
        $contact->forceFill([
            'metadata' => [
                'yfs_customer' => [
                    'status' => 'unique',
                    'app_user_id' => 13,
                    'display_name' => 'Olga Petrova',
                    'match_method' => 'phone',
                    'matched_at' => '2026-01-01T00:00:00Z',
                ],
            ],
        ]);

        $payload = $this->tool($contact)->execute([
            'name' => 'Petr Petrov',
            'system__caller_id' => '+15552220002',
        ]);

        $this->assertSame('unique', $payload['status']);
        $this->assertSame('already_identified', $payload['next_action']);
        $this->assertSame('Olga Petrova', $payload['customer']['display_name']);
        $this->assertSame(13, $contact->metadata['yfs_customer']['app_user_id']);
        $this->assertSame('Olga Petrova', $contact->metadata['yfs_customer']['display_name']);
        $this->assertSame('phone', $contact->metadata['yfs_customer']['match_method']);
    }

    private function tool(?MemoryVoiceContact $contact = null): ResolveCustomerIdentityVoiceTool
    {
        $sessions = \Mockery::mock(VoiceContactSessionResolver::class);
        $sessions->shouldReceive('findTrusted')->andReturn($contact);

        return new ResolveCustomerIdentityVoiceTool(
            new CustomerIdentityResolver($this->jfs),
            $this->store,
            $sessions,
        );
    }
}
