<?php

namespace Tests\Unit\Voice;

use App\Services\Voice\Identity\ElevenLabsTrustedCallContext;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ElevenLabsTrustedCallContextTest extends TestCase
{
    #[Test]
    public function only_system_dynamic_variables_are_trusted(): void
    {
        $context = ElevenLabsTrustedCallContext::fromRequest(Request::create('/unused', 'POST', [
            'phone' => '+15559990000',
            'caller_id' => '+15559990000',
            'system__caller_id' => '+15551230000',
            'system__conversation_id' => 'conv_abc',
            'on_behalf_of' => true,
        ]));

        $this->assertSame('+15551230000', $context->callerId);
        $this->assertSame('conv_abc', $context->conversationId);
        $this->assertTrue($context->onBehalfOf);
    }

    #[Test]
    public function llm_phone_and_caller_id_are_ignored(): void
    {
        $context = ElevenLabsTrustedCallContext::fromRequest(Request::create('/unused', 'POST', [
            'phone' => '+15559990000',
            'caller_id' => '+15559990000',
        ]));

        $this->assertSame('', $context->callerId);
        $this->assertSame('', $context->conversationId);
        $this->assertFalse($context->onBehalfOf);
    }
}
