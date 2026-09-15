<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VoiceCallsAdminAuthTest extends TestCase
{
    #[Test]
    public function guest_is_redirected_away_from_voice_assistant(): void
    {
        $this->get('/call-center')->assertRedirect('/login');
        $this->get('/call-center/contacts?q=555')->assertRedirect('/login');
    }
}
