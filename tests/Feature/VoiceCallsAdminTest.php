<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VoiceCall;
use App\Models\VoiceContact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VoiceCallsAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite is required for isolated feature tests.');
        }

        parent::setUp();
    }

    #[Test]
    public function guest_cannot_open_voice_assistant_calls(): void
    {
        $this->get(route('call-center.index'))
            ->assertRedirect(route('login'));
    }

    #[Test]
    public function admin_sees_calls_newest_first_and_can_open_details(): void
    {
        $contact = VoiceContact::query()->create([
            'phone_normalized' => '+15551234567',
            'phone_display' => '+1 555 123-4567',
            'name' => 'Ada',
            'preferred_language' => 'ru',
            'first_called_at' => now()->subDay(),
            'last_called_at' => now(),
            'calls_count' => 2,
        ]);

        $older = VoiceCall::query()->create([
            'voice_contact_id' => $contact->id,
            'elevenlabs_conversation_id' => 'conv_old',
            'phone' => '+15551234567',
            'language' => 'en',
            'started_at' => now()->subHour(),
            'duration_seconds' => 10,
            'status' => 'done',
            'transcript' => [
                ['speaker' => 'assistant', 'message' => 'Older hello'],
            ],
            'summary' => 'Older summary',
        ]);

        $newer = VoiceCall::query()->create([
            'voice_contact_id' => $contact->id,
            'elevenlabs_conversation_id' => 'conv_new',
            'phone' => '+15551234567',
            'language' => 'ru',
            'started_at' => now(),
            'duration_seconds' => 22,
            'status' => 'done',
            'transcript' => [
                ['speaker' => 'assistant', 'message' => 'Hello from YFS'],
                ['speaker' => 'client', 'message' => 'I need tickets'],
            ],
            'summary' => 'Asked about tickets',
        ]);

        $this->actingAs(User::factory()->create())
            ->get(route('call-center.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('CallCenter/Index')
                ->has('calls.data', 2)
                ->where('calls.data.0.id', $newer->id)
                ->where('calls.data.1.id', $older->id)
                ->where('calls.data.0.brief', 'Asked about tickets')
                ->where('selectedCall', null)
            );

        $this->actingAs(User::factory()->create())
            ->get(route('call-center.index', ['call' => $newer->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('CallCenter/Index')
                ->where('selectedCall.id', $newer->id)
                ->where('selectedCall.contact.name', 'Ada')
                ->where('selectedCall.contact.phone', '+1 555 123-4567')
                ->where('selectedCall.contact.preferred_language', 'ru')
                ->where('selectedCall.call_language', 'ru')
                ->where('selectedCall.contact.calls_count', 2)
                ->where('selectedCall.transcript.0.speaker', 'assistant')
                ->where('selectedCall.transcript.0.message', 'Hello from YFS')
                ->where('selectedCall.transcript.1.speaker', 'client')
                ->where('selectedCall.transcript.1.message', 'I need tickets')
            );
    }
}
