<?php

namespace Tests\Unit\Voice;

use App\Services\Bitrix\BitrixYfsLinker;
use App\Services\Voice\Identity\CustomerIdentityResolver;
use App\Services\Voice\Identity\CustomerIdentityResult;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeBitrixIdentityGateway;
use Tests\Support\FakeJfsReadService;
use Tests\TestCase;

class CustomerIdentityResolverBitrixFallbackTest extends TestCase
{
    private FakeJfsReadService $jfs;

    private FakeBitrixIdentityGateway $bitrix;

    private CustomerIdentityResolver $resolver;

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
                'email' => 'olga@example.com',
                'children' => ['Mia'],
            ],
            [
                'id' => 11,
                'name' => 'Anna Ivanova',
                'language' => 'ru',
                'phone' => '+1-555-111-0001',
                'email' => 'anna@example.com',
                'children' => ['Mia'],
            ],
            [
                'id' => 12,
                'name' => 'Petr Petrov',
                'language' => 'en',
                'phone' => '+1-555-111-0001',
                'email' => 'petr@example.com',
                'children' => ['Leo'],
            ],
        ];
        $this->bitrix = new FakeBitrixIdentityGateway;
        $this->resolver = new CustomerIdentityResolver(
            $this->jfs,
            $this->bitrix,
            new BitrixYfsLinker($this->jfs),
        );
    }

    #[Test]
    public function yfs_unique_phone_never_calls_bitrix(): void
    {
        $result = $this->resolver->resolveByPhoneFast('+15552220002');

        $this->assertTrue($result->isUnique());
        $this->assertSame(13, $result->yfsAppUserId);
        $this->assertSame('phone', $result->matchMethod);
        $this->assertSame(0, $this->bitrix->phoneLookups);
    }

    #[Test]
    public function yfs_not_found_phone_can_link_unique_bitrix_email_to_yfs(): void
    {
        $this->bitrix->phoneToContactIds['15559990000'] = [90];
        $this->bitrix->contactEmails[90] = ['olga@example.com'];

        $result = $this->resolver->resolveByPhoneFast('+15559990000');

        $this->assertTrue($result->isUnique());
        $this->assertSame(13, $result->yfsAppUserId);
        $this->assertSame('bitrix_phone_email', $result->matchMethod);
        $this->assertSame(1, $this->bitrix->phoneLookups);
        $encoded = json_encode($result) ?: '';
        $this->assertStringNotContainsString('olga@example.com', $encoded);
        $this->assertStringNotContainsString('90', $encoded);
    }

    #[Test]
    public function bitrix_contact_without_email_is_not_yfs_unique(): void
    {
        $this->bitrix->phoneToContactIds['15559990000'] = [90];
        $this->bitrix->contactEmails[90] = [];

        $result = $this->resolver->resolveByPhoneFast('+15559990000');

        $this->assertFalse($result->isUnique());
        $this->assertNull($result->yfsAppUserId);
        $this->assertSame(CustomerIdentityResult::NOT_FOUND, $result->status);
    }

    #[Test]
    public function bitrix_email_not_in_yfs_is_not_yfs_unique(): void
    {
        $this->bitrix->phoneToContactIds['15559990000'] = [90];
        $this->bitrix->contactEmails[90] = ['ghost@invalid.example'];

        $result = $this->resolver->resolveByPhoneFast('+15559990000');

        $this->assertSame(CustomerIdentityResult::NOT_FOUND, $result->status);
        $this->assertNull($result->yfsAppUserId);
    }

    #[Test]
    public function multiple_bitrix_phone_candidates_are_ambiguous(): void
    {
        $this->bitrix->phoneToContactIds['15559990000'] = [90, 91];

        $result = $this->resolver->resolveByPhoneFast('+15559990000');

        $this->assertSame(CustomerIdentityResult::AMBIGUOUS, $result->status);
        $this->assertNull($result->yfsAppUserId);
        $this->assertSame(0, $this->bitrix->emailLookups);
    }

    #[Test]
    public function yfs_ambiguous_phone_does_not_let_bitrix_pick(): void
    {
        $this->bitrix->phoneToContactIds['15551110001'] = [90];
        $this->bitrix->contactEmails[90] = ['olga@example.com'];

        $result = $this->resolver->resolveByPhoneFast('15551110001');

        $this->assertSame(CustomerIdentityResult::AMBIGUOUS, $result->status);
        $this->assertSame('phone', $result->matchMethod);
        $this->assertSame(0, $this->bitrix->phoneLookups);
    }

    #[Test]
    public function bitrix_failure_falls_back_to_yfs_result(): void
    {
        $this->bitrix->unavailable = true;

        $result = $this->resolver->resolveByPhoneFast('+15559990000');

        $this->assertSame(CustomerIdentityResult::NOT_FOUND, $result->status);
        $this->assertSame('phone', $result->matchMethod);
    }

    #[Test]
    public function spoken_name_yfs_miss_can_link_through_bitrix_email(): void
    {
        $this->bitrix->nameToContactIds['nobody here'] = [77];
        $this->bitrix->contactEmails[77] = ['olga@example.com'];

        $result = $this->resolver->resolveBySpokenHintsFast('Nobody Here');

        $this->assertTrue($result->isUnique());
        $this->assertSame(13, $result->yfsAppUserId);
        $this->assertSame('bitrix_name_email', $result->matchMethod);
        $this->assertGreaterThan(0, $this->bitrix->nameLookups);
    }

    #[Test]
    public function bitrix_contact_alone_never_becomes_yfs_unique(): void
    {
        $this->bitrix->nameToContactIds['crm only'] = [77];
        $this->bitrix->contactEmails[77] = [];

        $result = $this->resolver->resolveBySpokenHintsFast('Crm Only');

        $this->assertFalse($result->isUnique());
        $this->assertNull($result->yfsAppUserId);
    }

    #[Test]
    public function container_injects_bitrix_client_and_linker(): void
    {
        $resolver = $this->app->make(CustomerIdentityResolver::class);
        $ref = new \ReflectionClass($resolver);

        $this->assertInstanceOf(\App\Services\Bitrix\BitrixReadOnlyIdentityClient::class, $ref->getProperty('bitrix')->getValue($resolver));
        $this->assertInstanceOf(\App\Services\Bitrix\BitrixYfsLinker::class, $ref->getProperty('linker')->getValue($resolver));
    }
}
