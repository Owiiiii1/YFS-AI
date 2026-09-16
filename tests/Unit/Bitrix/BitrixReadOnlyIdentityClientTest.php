<?php

namespace Tests\Unit\Bitrix;

use App\Services\Bitrix\BitrixEntityMatch;
use App\Services\Bitrix\BitrixReadOnlyIdentityClient;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BitrixReadOnlyIdentityClientTest extends TestCase
{
    private BitrixReadOnlyIdentityClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.bitrix.webhook_url' => 'https://bitrix.example/rest/1/test-token']);
        $this->client = new BitrixReadOnlyIdentityClient;
    }

    #[Test]
    public function phone_unique_uses_duplicate_findbycomm(): void
    {
        Http::fake([
            'https://bitrix.example/rest/1/test-token/crm.duplicate.findbycomm.json' => Http::response([
                'result' => ['CONTACT' => [44]],
            ]),
        ]);

        $match = $this->client->findContactIdsByPhone('+15551230000');

        $this->assertTrue($match->isUnique());
        $this->assertSame([44], $match->contactIds);
        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return str_contains($request->url(), 'crm.duplicate.findbycomm')
                && ($body['type'] ?? null) === 'PHONE'
                && ($body['entity_type'] ?? null) === 'CONTACT'
                && ! isset($body['filter']['FIND'])
                && ! isset($body['filter']['%PHONE']);
        });
    }

    #[Test]
    public function phone_ambiguous_does_not_guess(): void
    {
        Http::fake([
            'https://bitrix.example/rest/1/test-token/crm.duplicate.findbycomm.json' => Http::response([
                'result' => ['CONTACT' => [10, 11]],
            ]),
        ]);

        $match = $this->client->findContactIdsByPhone('5551230000');

        $this->assertSame(BitrixEntityMatch::AMBIGUOUS, $match->status);
        $this->assertSame(2, $match->matchCount);
    }

    #[Test]
    public function phone_not_found(): void
    {
        Http::fake([
            'https://bitrix.example/rest/1/test-token/crm.duplicate.findbycomm.json' => Http::response(['result' => []]),
            'https://bitrix.example/rest/1/test-token/telephony.externalCall.searchCrmEntities.json' => Http::response(['result' => []]),
        ]);

        $match = $this->client->findContactIdsByPhone('15550000000');

        $this->assertSame(BitrixEntityMatch::NOT_FOUND, $match->status);
    }

    #[Test]
    public function unavailable_when_http_error(): void
    {
        Http::fake([
            'https://bitrix.example/rest/1/test-token/crm.duplicate.findbycomm.json' => Http::response(['error' => 'SERVER_ERROR'], 500),
        ]);

        $match = $this->client->findContactIdsByPhone('15551230000');

        $this->assertSame(BitrixEntityMatch::UNAVAILABLE, $match->status);
    }

    #[Test]
    public function timeout_is_unavailable(): void
    {
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('timed out');
        });

        $match = $this->client->findContactIdsByPhone('15551230000');

        $this->assertSame(BitrixEntityMatch::UNAVAILABLE, $match->status);
    }

    #[Test]
    public function malformed_response_is_unavailable(): void
    {
        Http::fake([
            'https://bitrix.example/rest/1/test-token/crm.duplicate.findbycomm.json' => Http::response('not-json', 200),
        ]);

        $match = $this->client->findContactIdsByPhone('15551230000');

        $this->assertSame(BitrixEntityMatch::UNAVAILABLE, $match->status);
    }

    #[Test]
    public function rate_limit_is_unavailable(): void
    {
        Http::fake([
            'https://bitrix.example/rest/1/test-token/crm.duplicate.findbycomm.json' => Http::response(['error' => 'QUERY_LIMIT_EXCEEDED'], 429),
        ]);

        $match = $this->client->findContactIdsByPhone('15551230000');

        $this->assertSame(BitrixEntityMatch::UNAVAILABLE, $match->status);
    }

    #[Test]
    public function write_methods_are_not_allowlisted(): void
    {
        $allowed = (new \ReflectionClass(BitrixReadOnlyIdentityClient::class))
            ->getConstant('ALLOWED_METHODS');

        $this->assertContains('crm.duplicate.findbycomm', $allowed);
        $this->assertContains('telephony.externalCall.searchCrmEntities', $allowed);
        $this->assertContains('crm.contact.list', $allowed);
        $this->assertContains('crm.contact.get', $allowed);
        $this->assertNotContains('crm.contact.add', $allowed);
        $this->assertNotContains('crm.contact.update', $allowed);
        $this->assertNotContains('crm.deal.add', $allowed);
        $this->assertNotContains('crm.lead.add', $allowed);
    }

    #[Test]
    public function name_search_does_not_use_find_or_phone_like(): void
    {
        Http::fake([
            'https://bitrix.example/rest/1/test-token/crm.contact.list.json' => Http::response([
                'result' => [['ID' => '9']],
                'total' => 1,
            ]),
        ]);

        $match = $this->client->findContactIdsByName('Olga Petrova');

        $this->assertTrue($match->isUnique());
        Http::assertSent(function ($request): bool {
            $body = $request->data();
            $filter = $body['filter'] ?? [];

            return str_contains($request->url(), 'crm.contact.list')
                && ! array_key_exists('FIND', $filter)
                && ! array_key_exists('%PHONE', $filter)
                && ! array_key_exists('PHONE', $filter);
        });
    }
}
