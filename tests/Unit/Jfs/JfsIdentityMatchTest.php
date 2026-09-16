<?php

namespace Tests\Unit\Jfs;

use App\Services\Jfs\JfsIdentityMatch;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class JfsIdentityMatchTest extends TestCase
{
    #[Test]
    public function us_10_and_11_digit_numbers_share_keys(): void
    {
        $ten = JfsIdentityMatch::phoneDigitKeys('5551234567');
        $eleven = JfsIdentityMatch::phoneDigitKeys('+1 (555) 123-4567');
        $masked = JfsIdentityMatch::phoneDigitKeys('+1-555-123-4567');

        $this->assertContains('5551234567', $ten);
        $this->assertContains('15551234567', $ten);
        $this->assertSame($ten, $eleven);
        $this->assertSame($ten, $masked);
        $this->assertTrue(JfsIdentityMatch::phonesMatch('+1-555-123-4567', '5551234567'));
        $this->assertTrue(JfsIdentityMatch::phonesMatch('+1-555-123-4567', '+15551234567'));
    }

    #[Test]
    public function phone_keys_reject_short_or_overbroad_values(): void
    {
        $this->assertSame([], JfsIdentityMatch::phoneDigitKeys('123'));
        $this->assertFalse(JfsIdentityMatch::phonesMatch('15551234567', '15559876543'));
        $this->assertFalse(JfsIdentityMatch::phonesMatch('380501234567', '0501234567'));
    }

    #[Test]
    public function name_match_is_word_based_and_order_insensitive(): void
    {
        $this->assertTrue(JfsIdentityMatch::nameMatches('Anna Ivanova', 'anna ivanova'));
        $this->assertTrue(JfsIdentityMatch::nameMatches('Anna Ivanova', 'Ivanova Anna'));
        $this->assertTrue(JfsIdentityMatch::nameMatches('Anna Ivanova', 'Anna'));
        $this->assertTrue(JfsIdentityMatch::nameMatches('Anna Ivanova', 'Ivanova'));
        $this->assertFalse(JfsIdentityMatch::nameMatches('Anna Ivanova', 'Ann'));
        $this->assertFalse(JfsIdentityMatch::nameMatches('Anna Ivanova', 'Iva'));
        $this->assertFalse(JfsIdentityMatch::nameMatches('Anna Ivanova', 'Petr Ivanov'));
    }
}
