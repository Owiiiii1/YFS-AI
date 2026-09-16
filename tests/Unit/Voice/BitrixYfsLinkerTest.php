<?php

namespace Tests\Unit\Bitrix;

use App\Services\Bitrix\BitrixYfsLinker;
use App\Services\Voice\Identity\CustomerIdentityResult;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeJfsReadService;
use Tests\TestCase;

class BitrixYfsLinkerTest extends TestCase
{
    private FakeJfsReadService $jfs;

    private BitrixYfsLinker $linker;

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
        ];
        $this->linker = new BitrixYfsLinker($this->jfs);
    }

    #[Test]
    public function unique_email_links_to_yfs_without_exposing_email(): void
    {
        $result = $this->linker->resolveFromEmails(['olga@example.com'], 'bitrix_phone_email');

        $this->assertTrue($result->isUnique());
        $this->assertSame(13, $result->yfsAppUserId);
        $this->assertSame('Olga Petrova', $result->displayName);
        $encoded = json_encode($result) ?: '';
        $this->assertStringNotContainsString('olga@example.com', $encoded);
        $this->assertStringNotContainsString('555-222', $encoded);
    }

    #[Test]
    public function missing_email_is_not_found(): void
    {
        $result = $this->linker->resolveFromEmails([], 'bitrix_phone_email');

        $this->assertSame(CustomerIdentityResult::NOT_FOUND, $result->status);
        $this->assertNull($result->yfsAppUserId);
    }

    #[Test]
    public function unknown_email_is_not_yfs_identity(): void
    {
        $result = $this->linker->resolveFromEmails(['nobody@invalid.example'], 'bitrix_phone_email');

        $this->assertSame(CustomerIdentityResult::NOT_FOUND, $result->status);
        $this->assertNull($result->yfsAppUserId);
    }

    #[Test]
    public function emails_of_two_yfs_customers_are_ambiguous(): void
    {
        $result = $this->linker->resolveFromEmails(['olga@example.com', 'anna@example.com'], 'bitrix_name_email');

        $this->assertSame(CustomerIdentityResult::AMBIGUOUS, $result->status);
        $this->assertNull($result->yfsAppUserId);
    }

    #[Test]
    public function jfs_failure_is_unavailable(): void
    {
        $this->jfs->readFailed = true;

        $result = $this->linker->resolveFromEmails(['olga@example.com'], 'bitrix_phone_email');

        $this->assertSame(CustomerIdentityResult::SOURCE_UNAVAILABLE, $result->status);
    }
}
