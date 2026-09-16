<?php

namespace Tests\Unit\Voice;

use App\Services\Voice\Identity\CustomerIdentityResolver;
use App\Services\Voice\Identity\CustomerIdentityResult;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeJfsReadService;
use Tests\TestCase;

class CustomerIdentityResolverTest extends TestCase
{
    private FakeJfsReadService $jfs;

    private CustomerIdentityResolver $resolver;

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
            [
                'id' => 15,
                'name' => 'Staff User',
                'language' => 'en',
                'phone' => '+1-555-333-0003',
                'role' => 'admin',
                'children' => [],
            ],
        ];
        $this->resolver = new CustomerIdentityResolver($this->jfs);
    }

    #[Test]
    public function unique_phone_is_identified_without_sensitive_fields(): void
    {
        $result = $this->resolver->resolveByPhone('+15552220002');

        $this->assertSame(CustomerIdentityResult::UNIQUE, $result->status);
        $this->assertSame(13, $result->yfsAppUserId);
        $this->assertSame('Olga Petrova', $result->displayName);
        $this->assertSame('uk', $result->preferredLanguage);
        $this->assertSame(1, $this->jfs->findClientsByPhoneCalls);
        $this->assertSame(0, $this->jfs->writeCalls);
        $encoded = json_encode($result) ?: '';
        $this->assertStringNotContainsString('555-222', $encoded);
        $this->assertStringNotContainsString('+1-', $encoded);
        $this->assertStringNotContainsString('Mia', $encoded);
        $this->assertStringNotContainsString('@', $encoded);
    }

    #[Test]
    public function masked_jfs_phone_matches_normalized_caller_id_and_us_10_vs_11(): void
    {
        $this->jfs->clients = [[
            'id' => 21,
            'name' => 'Unique Caller',
            'language' => 'en',
            'phone' => '+1-555-123-4567',
            'children' => [],
        ]];

        $fromE164 = $this->resolver->resolveByPhone('+15551234567');
        $fromTen = $this->resolver->resolveByPhone('5551234567');

        $this->assertSame(CustomerIdentityResult::UNIQUE, $fromE164->status);
        $this->assertSame(21, $fromE164->yfsAppUserId);
        $this->assertSame(CustomerIdentityResult::UNIQUE, $fromTen->status);
        $this->assertSame(21, $fromTen->yfsAppUserId);
    }

    #[Test]
    public function duplicate_phone_is_ambiguous_and_does_not_select_a_client(): void
    {
        $result = $this->resolver->resolveByPhone('15551110001');

        $this->assertSame(CustomerIdentityResult::AMBIGUOUS, $result->status);
        $this->assertSame(2, $result->matchCount);
        $this->assertNull($result->yfsAppUserId);
        $this->assertNull($result->displayName);
    }

    #[Test]
    public function unknown_phone_is_not_found(): void
    {
        $result = $this->resolver->resolveByPhone('+15559990000');

        $this->assertSame(CustomerIdentityResult::NOT_FOUND, $result->status);
        $this->assertNull($result->yfsAppUserId);
    }

    #[Test]
    public function unavailable_jfs_is_not_treated_as_unknown_caller(): void
    {
        $this->jfs->configured = false;

        $result = $this->resolver->resolveByPhone('+15552220002');

        $this->assertSame(CustomerIdentityResult::SOURCE_UNAVAILABLE, $result->status);
        $this->assertNull($result->yfsAppUserId);
    }

    #[Test]
    public function unique_and_ambiguous_and_unknown_names(): void
    {
        $unique = $this->resolver->resolveByName('Olga Petrova');
        $reversed = $this->resolver->resolveByName('Ivanova Anna');
        $ambiguous = $this->resolver->resolveByName('Ivanova');
        $unknown = $this->resolver->resolveByName('Nobody Here');

        $this->assertSame(CustomerIdentityResult::UNIQUE, $unique->status);
        $this->assertSame(13, $unique->yfsAppUserId);
        $this->assertSame(CustomerIdentityResult::UNIQUE, $reversed->status);
        $this->assertSame(11, $reversed->yfsAppUserId);
        $this->assertSame(CustomerIdentityResult::AMBIGUOUS, $ambiguous->status);
        $this->assertNull($ambiguous->displayName);
        $this->assertSame(CustomerIdentityResult::NOT_FOUND, $unknown->status);
    }

    #[Test]
    public function child_name_resolves_parent_or_stays_ambiguous(): void
    {
        $unique = $this->resolver->resolveByChildName('Leo');
        $ambiguous = $this->resolver->resolveByChildName('Mia');
        $unknown = $this->resolver->resolveByChildName('Unknownkid');

        $this->assertSame(CustomerIdentityResult::UNIQUE, $unique->status);
        $this->assertSame(12, $unique->yfsAppUserId);
        $this->assertSame('Petr Petrov', $unique->displayName);
        $this->assertSame(CustomerIdentityResult::AMBIGUOUS, $ambiguous->status);
        $this->assertNull($ambiguous->displayName);
        $this->assertSame(CustomerIdentityResult::NOT_FOUND, $unknown->status);
        $encoded = json_encode($ambiguous) ?: '';
        $this->assertStringNotContainsString('Mia', $encoded);
        $this->assertStringNotContainsString('Leo', $encoded);
    }

    #[Test]
    public function spoken_hints_intersect_name_and_child_without_guessing(): void
    {
        $resolved = $this->resolver->resolveBySpokenHints('Ivanova', 'Mia');
        $stillAmbiguous = $this->resolver->resolveBySpokenHints('Ivanova', null);

        $this->assertSame(CustomerIdentityResult::UNIQUE, $resolved->status);
        $this->assertSame(11, $resolved->yfsAppUserId);
        $this->assertSame(CustomerIdentityResult::AMBIGUOUS, $stillAmbiguous->status);
    }
}
