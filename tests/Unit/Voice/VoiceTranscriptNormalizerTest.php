<?php

namespace Tests\Unit\Voice;

use App\Services\Voice\Calls\VoiceTranscriptNormalizer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VoiceTranscriptNormalizerTest extends TestCase
{
    private VoiceTranscriptNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normalizer = new VoiceTranscriptNormalizer;
    }

    #[Test]
    public function it_maps_elevenlabs_turns_to_client_and_assistant(): void
    {
        $turns = $this->normalizer->normalize([
            ['role' => 'agent', 'message' => 'Hello', 'time_in_call_secs' => 0, 'tool_calls' => [['type' => 'webhook']]],
            ['role' => 'user', 'message' => 'Hi there', 'time_in_call_secs' => 2],
            ['role' => 'agent', 'message' => null, 'tool_calls' => [['name' => 'lookup']]],
            'not-an-array',
            ['role' => 'system', 'message' => 'ignore me'],
        ]);

        $this->assertCount(2, $turns);
        $this->assertSame('agent', $turns[0]['role']);
        $this->assertSame('assistant', $turns[0]['speaker']);
        $this->assertSame('Hello', $turns[0]['message']);
        $this->assertSame('user', $turns[1]['role']);
        $this->assertSame('client', $turns[1]['speaker']);
        $this->assertSame('Hi there', $turns[1]['message']);
        $this->assertArrayNotHasKey('tool_calls', $turns[0]);
    }

    #[Test]
    public function missing_or_invalid_transcript_is_empty(): void
    {
        $this->assertSame([], $this->normalizer->normalize(null));
        $this->assertSame([], $this->normalizer->normalize('not json'));
        $this->assertSame([], $this->normalizer->normalize([]));
    }

    #[Test]
    public function preview_is_a_safe_plain_text_excerpt(): void
    {
        $preview = $this->normalizer->preview([
            ['message' => 'Hello from the agent.'],
            ['message' => 'I need tickets.'],
        ], 20);

        $this->assertSame('Hello from the agen…', $preview);
        $this->assertNull($this->normalizer->preview([]));
    }
}
