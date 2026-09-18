<?php

namespace Tests\Unit\Voice;

use App\Models\VoiceFollowup;
use App\Services\Voice\Followup\VoiceFollowupTelegramFormatter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VoiceFollowupTelegramFormatterTest extends TestCase
{
    #[Test]
    public function omits_empty_fields_internal_ids_and_marks_recovered_messages(): void
    {
        $formatter = new VoiceFollowupTelegramFormatter;
        $followup = new VoiceFollowup([
            'department' => VoiceFollowup::DEPARTMENT_SALES,
            'reason' => 'Please call back about the application.',
            'callback_requested' => true,
            'callback_phone' => '+15551231212',
            'preferred_callback_time' => 'afternoon',
            'customer_name' => 'Alex',
            'child_name' => null,
            'show_city' => '',
            'summary' => 'Unknown lead. Identity search was not unique.',
            'voice_call_id' => 42,
        ]);

        $live = $formatter->format($followup, false);
        $recovered = $formatter->format($followup, true);

        $this->assertStringContainsString('📞 Voice · Callback requested', $live);
        $this->assertStringContainsString('Department: Sales', $live);
        $this->assertStringContainsString('Customer: Alex', $live);
        $this->assertStringContainsString('Callback: +15551231212', $live);
        $this->assertStringContainsString('Preferred time: afternoon', $live);
        $this->assertStringContainsString('Please call back about the application.', $live);
        $this->assertStringContainsString('Unknown lead. Identity search was not unique.', $live);
        $this->assertStringContainsString('call-center?call=42', $live);
        $this->assertStringNotContainsString('Child:', $live);
        $this->assertStringNotContainsString('Show:', $live);
        $this->assertStringNotContainsString('voice_contact_id', $live);
        $this->assertStringNotContainsString('app_user_id', $live);

        $this->assertStringContainsString('⚠️ Voice · Follow-up recovered after call', $recovered);
        $this->assertStringNotContainsString('📞 Voice · Callback requested', $recovered);
    }
}
