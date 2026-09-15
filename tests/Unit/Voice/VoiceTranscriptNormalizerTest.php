<?php

namespace Tests\Unit\Voice;

use App\Services\Voice\Calls\VoiceTranscriptNormalizer;
use App\Services\Voice\Calls\VoiceTranscriptSanitizer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VoiceTranscriptNormalizerTest extends TestCase
{
    private VoiceTranscriptNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normalizer = new VoiceTranscriptNormalizer(new VoiceTranscriptSanitizer);
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

    #[Test]
    public function rus_wrappers_are_removed_and_spoken_text_remains(): void
    {
        $turns = $this->normalizer->normalize([
            ['role' => 'agent', 'message' => '<Rus>Доброжелательно> Конечно, буду говорить по-русски.</Rus>'],
        ]);

        $this->assertCount(1, $turns);
        $this->assertSame('Конечно, буду говорить по-русски.', $turns[0]['message']);
        $this->assertStringNotContainsString('<Rus>', $turns[0]['message']);
        $this->assertStringNotContainsString('</Rus>', $turns[0]['message']);
        $this->assertStringNotContainsString('Доброжелательно>', $turns[0]['message']);
    }

    #[Test]
    public function equivalent_language_tags_are_cleaned(): void
    {
        $turns = $this->normalizer->normalize([
            ['role' => 'agent', 'message' => '<Ukr>Звичайно, розкажу українською.</Ukr>'],
            ['role' => 'agent', 'message' => '<English>Sure, I can continue in English.</English>'],
        ]);

        $this->assertSame('Звичайно, розкажу українською.', $turns[0]['message']);
        $this->assertSame('Sure, I can continue in English.', $turns[1]['message']);
        $this->assertStringNotContainsString('<Ukr>', $turns[0]['message']);
        $this->assertStringNotContainsString('<English>', $turns[1]['message']);
    }
}
