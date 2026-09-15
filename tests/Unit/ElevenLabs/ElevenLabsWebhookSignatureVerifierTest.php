<?php

namespace Tests\Unit\ElevenLabs;

use App\Services\ElevenLabs\ElevenLabsWebhookSignatureVerifier;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ElevenLabsWebhookSignatureVerifierTest extends TestCase
{
    private ElevenLabsWebhookSignatureVerifier $verifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->verifier = new ElevenLabsWebhookSignatureVerifier;
    }

    #[Test]
    public function valid_signature_is_accepted(): void
    {
        $secret = 'test-post-call-secret';
        $body = '{"type":"post_call_transcription"}';
        $timestamp = 1_700_000_000;
        $header = 't='.$timestamp.',v0='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);

        $this->assertTrue($this->verifier->verify($body, $header, $secret, $timestamp));
    }

    #[Test]
    public function missing_or_wrong_signature_is_rejected(): void
    {
        $secret = 'test-post-call-secret';
        $body = '{"type":"post_call_transcription"}';

        $this->assertFalse($this->verifier->verify($body, null, $secret));
        $this->assertFalse($this->verifier->verify($body, 't=1,v0=deadbeef', $secret));
        $this->assertFalse($this->verifier->verify($body, 't=1700000000,v0='.hash_hmac('sha256', '1700000000.'.$body, $secret), ''));
        $this->assertFalse($this->verifier->verify($body, 't=1,v0='.hash_hmac('sha256', '1.'.$body, $secret), $secret, 1_700_000_000));
    }
}
