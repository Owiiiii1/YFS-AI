<?php

namespace Tests\Unit\Voice;

use App\Services\Voice\Contacts\VoiceContactDirectory;
use App\Services\Voice\Identity\VoiceContactSessionResolver;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\MemoryVoiceContact;
use Tests\TestCase;

class VoiceContactSessionResolverTest extends TestCase
{
    #[Test]
    public function conversation_identity_wins_over_caller_id(): void
    {
        $conversationContact = new MemoryVoiceContact;
        $directory = \Mockery::mock(VoiceContactDirectory::class);
        $directory->shouldReceive('findByElevenLabsConversationId')
            ->once()
            ->with('conv_olga')
            ->andReturn($conversationContact);
        $directory->shouldReceive('findByCallerId')->never();

        $resolved = (new VoiceContactSessionResolver($directory))
            ->findExistingTrusted('+15551110001', 'conv_olga');

        $this->assertSame($conversationContact, $resolved);
    }

    #[Test]
    public function caller_id_fallback_does_not_create_a_contact(): void
    {
        $existing = new MemoryVoiceContact;
        $directory = \Mockery::mock(VoiceContactDirectory::class);
        $directory->shouldReceive('findByElevenLabsConversationId')
            ->once()
            ->with(null)
            ->andReturn(null);
        $directory->shouldReceive('findByCallerId')
            ->once()
            ->with('+15552220002')
            ->andReturn($existing);
        $directory->shouldReceive('findOrCreateFromCallerId')->never();

        $resolved = (new VoiceContactSessionResolver($directory))
            ->findExistingTrusted('+15552220002', null);

        $this->assertSame($existing, $resolved);
    }
}
