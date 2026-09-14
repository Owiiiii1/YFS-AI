<?php

namespace Tests\Unit;

use App\Services\Meta\InvalidMetaSignedRequestException;
use App\Services\Meta\MetaSignedRequestVerifier;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MetaSignedRequestVerifierTest extends TestCase
{
    private const SECRET = 'test-instagram-app-secret';

    #[Test]
    public function it_parses_a_valid_signed_request(): void
    {
        $payload = ['algorithm' => 'HMAC-SHA256', 'user_id' => '17841400000000000', 'issued_at' => 1710000000];
        $signed = $this->signedRequest($payload);

        $parsed = (new MetaSignedRequestVerifier())->parse($signed, self::SECRET);

        $this->assertSame('17841400000000000', $parsed['user_id']);
        $this->assertSame('HMAC-SHA256', $parsed['algorithm']);
    }

    #[Test]
    public function it_rejects_an_invalid_signature(): void
    {
        $signed = $this->signedRequest(['algorithm' => 'HMAC-SHA256', 'user_id' => '1'], 'other-secret');

        $this->expectException(InvalidMetaSignedRequestException::class);
        $this->expectExceptionMessage(InvalidMetaSignedRequestException::REASON_INVALID_SIGNATURE);

        (new MetaSignedRequestVerifier())->parse($signed, self::SECRET);
    }

    #[Test]
    public function it_rejects_a_malformed_signed_request(): void
    {
        $this->expectException(InvalidMetaSignedRequestException::class);
        $this->expectExceptionMessage(InvalidMetaSignedRequestException::REASON_MALFORMED);

        (new MetaSignedRequestVerifier())->parse('not-a-signed-request', self::SECRET);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function signedRequest(array $payload, string $secret = self::SECRET): string
    {
        $encodedPayload = rtrim(strtr(base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $encodedSignature = rtrim(strtr(base64_encode(hash_hmac('sha256', $encodedPayload, $secret, true)), '+/', '-_'), '=');

        return $encodedSignature.'.'.$encodedPayload;
    }
}
