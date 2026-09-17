<?php

namespace Tests\Unit\Voice;

use App\Services\Voice\Identity\CustomerIdentityResult;
use App\Services\Voice\Identity\VoiceContactSessionResolver;
use App\Services\Voice\Identity\VoiceCustomerIdentityStore;
use App\Services\Voice\Tools\GetCustomerContextVoiceTool;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeJfsReadService;
use Tests\Support\MemoryVoiceContact;
use Tests\TestCase;

class GetCustomerContextVoiceToolTest extends TestCase
{
    private FakeJfsReadService $jfs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->jfs = new FakeJfsReadService;
        $this->jfs->clients = [
            [
                'id' => 13,
                'name' => 'Olga Petrova',
                'language' => 'uk',
                'phone' => '+1-555-222-0002',
                'children' => [
                    [
                        'display_name' => 'Mia',
                        'participations' => [[
                            'show' => 'YFS Miami',
                            'city' => 'Miami',
                            'date' => '2099-03-07',
                            'date_announced' => true,
                            'is_past' => false,
                            'status' => 'in_progress',
                            'package' => 'Premium',
                        ]],
                    ],
                ],
            ],
            [
                'id' => 14,
                'name' => 'Maria Ivanova',
                'language' => 'en',
                'phone' => '+1-555-444-0004',
                'children' => [],
            ],
            [
                'id' => 15,
                'name' => 'Anna Ivanova',
                'language' => 'ru',
                'phone' => '+1-555-111-0001',
                'children' => [
                    [
                        'display_name' => 'Leo',
                        'participations' => [[
                            'show' => 'YFS Chicago',
                            'city' => 'Chicago',
                            'date' => '2024-12-01',
                            'date_announced' => true,
                            'is_past' => true,
                            'status' => 'completed',
                            'category' => 'family_look',
                            'package' => 'Basic',
                        ]],
                    ],
                    [
                        'display_name' => 'Sam',
                        'participations' => [[
                            'show' => 'YFS Miami',
                            'city' => 'Miami',
                            'date' => '2099-03-07',
                            'date_announced' => true,
                            'is_past' => false,
                            'status' => 'ready',
                            'package' => 'Premium',
                        ]],
                    ],
                ],
            ],
        ];
    }

    #[Test]
    public function verified_conversation_returns_the_bound_customer(): void
    {
        $payload = $this->tool($this->boundContact(13, 'Olga Petrova'))->execute([
            'system__conversation_id' => 'conv_olga',
            'customer_id' => 14,
            'name' => 'Maria Ivanova',
        ]);

        $this->assertSame('ok', $payload['status']);
        $this->assertTrue($payload['ok']);
        $this->assertSame('Olga Petrova', $payload['customer']['display_name']);
        $this->assertSame('uk', $payload['customer']['preferred_language']);
        $this->assertSame(1, $this->jfs->loadCustomerContextCalls);
        $this->assertSame(0, $this->jfs->writeCalls);
    }

    #[Test]
    public function verified_customer_returns_children_and_participations(): void
    {
        $payload = $this->tool($this->boundContact(13, 'Olga Petrova'))->execute([
            'system__conversation_id' => 'conv_olga',
        ]);

        $this->assertSame([[
            'display_name' => 'Mia',
            'participations' => [[
                'show' => 'YFS Miami',
                'city' => 'Miami',
                'date' => '2099-03-07',
                'date_announced' => true,
                'is_past' => false,
                'status' => 'in_progress',
                'package' => 'Premium',
            ]],
        ]], $payload['children']);
        $this->assertSame('Premium', $payload['customer']['package']);
    }

    #[Test]
    public function customer_without_children_returns_empty_list(): void
    {
        $payload = $this->tool($this->boundContact(14, 'Maria Ivanova'))->execute([
            'system__conversation_id' => 'conv_maria',
        ]);

        $this->assertSame('ok', $payload['status']);
        $this->assertSame([], $payload['children']);
        $this->assertArrayNotHasKey('package', $payload['customer']);
    }

    #[Test]
    public function customer_with_several_children_keeps_each_child_and_does_not_guess_package(): void
    {
        $payload = $this->tool($this->boundContact(15, 'Anna Ivanova'))->execute([
            'system__conversation_id' => 'conv_anna',
        ]);

        $this->assertSame('ok', $payload['status']);
        $this->assertSame(['Leo', 'Sam'], array_column($payload['children'], 'display_name'));
        $this->assertSame('family_look', $payload['children'][0]['participations'][0]['category']);
        $this->assertArrayNotHasKey('package', $payload['customer']);
    }

    #[Test]
    public function unidentified_conversation_requires_identity_and_does_not_read_yfs_context(): void
    {
        $contact = new MemoryVoiceContact;
        $contact->forceFill(['metadata' => []]);

        $payload = $this->tool($contact)->execute([
            'system__conversation_id' => 'conv_unknown',
            'customer_id' => 13,
            'name' => 'Olga Petrova',
        ]);

        $this->assertSame([
            'ok' => true,
            'tool' => GetCustomerContextVoiceTool::NAME,
            'status' => 'identity_required',
        ], $payload);
        $this->assertSame(0, $this->jfs->loadCustomerContextCalls);
        $this->assertArrayNotHasKey('children', $payload);
        $this->assertArrayNotHasKey('customer', $payload);
    }

    #[Test]
    public function request_body_cannot_select_another_customer(): void
    {
        $payload = $this->tool($this->boundContact(13, 'Olga Petrova'))->execute([
            'system__conversation_id' => 'conv_olga',
            'customer_id' => 15,
            'app_user_id' => 15,
            'name' => 'Anna Ivanova',
            'email' => 'anna@example.com',
            'phone' => '+15551110001',
            'child_id' => 99,
        ]);

        $this->assertSame('ok', $payload['status']);
        $this->assertSame('Olga Petrova', $payload['customer']['display_name']);
        $this->assertSame(['Mia'], array_column($payload['children'], 'display_name'));
        $encoded = json_encode($payload) ?: '';
        $this->assertStringNotContainsString('Anna', $encoded);
        $this->assertStringNotContainsString('Leo', $encoded);
        $this->assertStringNotContainsString('anna@example.com', $encoded);
    }

    #[Test]
    public function caller_id_does_not_replace_conversation_bound_identity(): void
    {
        $sessions = \Mockery::mock(VoiceContactSessionResolver::class);
        $sessions->shouldReceive('findExistingTrusted')
            ->once()
            ->with('+15551110001', 'conv_olga')
            ->andReturn($this->boundContact(13, 'Olga Petrova'));

        $payload = (new GetCustomerContextVoiceTool(
            $this->jfs,
            $sessions,
            new VoiceCustomerIdentityStore,
        ))->execute([
            'system__conversation_id' => 'conv_olga',
            'system__caller_id' => '+15551110001',
        ]);

        $this->assertSame('Olga Petrova', $payload['customer']['display_name']);
        $this->assertSame(['Mia'], array_column($payload['children'], 'display_name'));
    }

    #[Test]
    public function yfs_unavailable_is_safe(): void
    {
        $this->jfs->configured = false;

        $payload = $this->tool($this->boundContact(13, 'Olga Petrova'))->execute([
            'system__conversation_id' => 'conv_olga',
        ]);

        $this->assertSame([
            'ok' => false,
            'tool' => GetCustomerContextVoiceTool::NAME,
            'status' => 'unavailable',
        ], $payload);
        $this->assertArrayNotHasKey('children', $payload);
        $this->assertArrayNotHasKey('customer', $payload);
        $this->assertArrayNotHasKey('error', $payload);
    }

    #[Test]
    public function response_omits_internal_and_sensitive_fields(): void
    {
        $payload = $this->tool($this->boundContact(13, 'Olga Petrova'))->execute([
            'system__conversation_id' => 'conv_olga',
        ]);

        $encoded = json_encode($payload) ?: '';
        $this->assertSame(['ok', 'tool', 'status', 'customer', 'children'], array_keys($payload));
        $this->assertSame(['display_name', 'preferred_language', 'package'], array_keys($payload['customer']));
        $this->assertStringNotContainsString('app_user', $encoded);
        $this->assertStringNotContainsString('"id"', $encoded);
        $this->assertStringNotContainsString('phone', $encoded);
        $this->assertStringNotContainsString('email', $encoded);
        $this->assertStringNotContainsString('password', $encoded);
        $this->assertStringNotContainsString('badge', $encoded);
        $this->assertStringNotContainsString('contract', $encoded);
        $this->assertStringNotContainsString('payment', $encoded);
        $this->assertStringNotContainsString('555', $encoded);
        $this->assertStringNotContainsString('@', $encoded);
        $this->assertSame(0, $this->jfs->writeCalls);
        $this->assertSame(0, $this->jfs->findClientByEmailCalls);
    }

    private function boundContact(int $appUserId, string $displayName): MemoryVoiceContact
    {
        $contact = new MemoryVoiceContact;
        $contact->forceFill([
            'metadata' => [
                'yfs_customer' => [
                    'status' => CustomerIdentityResult::UNIQUE,
                    'app_user_id' => $appUserId,
                    'display_name' => $displayName,
                    'match_method' => 'name',
                    'matched_at' => '2026-01-01T00:00:00Z',
                ],
            ],
        ]);

        return $contact;
    }

    private function tool(?MemoryVoiceContact $contact): GetCustomerContextVoiceTool
    {
        $sessions = \Mockery::mock(VoiceContactSessionResolver::class);
        $sessions->shouldReceive('findExistingTrusted')->andReturn($contact);

        return new GetCustomerContextVoiceTool(
            $this->jfs,
            $sessions,
            new VoiceCustomerIdentityStore,
        );
    }
}
