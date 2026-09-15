<?php

namespace Tests\Unit\Voice;

use App\Services\Voice\Phone\PhoneNumberNormalizer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PhoneNumberNormalizerTest extends TestCase
{
    private PhoneNumberNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normalizer = new PhoneNumberNormalizer;
    }

    #[Test]
    public function international_numbers_normalize_to_e164(): void
    {
        $this->assertSame('+15551234567', $this->normalizer->normalize('+1 555 123-4567'));
        $this->assertSame('+15551234567', $this->normalizer->normalize('0015551234567'));
        $this->assertSame('+15551234567', $this->normalizer->normalize('15551234567'));
        $this->assertSame('+380501234567', $this->normalizer->normalize('+38 (050) 123-45-67'));
    }

    #[Test]
    public function the_same_number_in_different_formats_shares_one_key(): void
    {
        $this->assertSame(
            $this->normalizer->normalize('+1 (555) 123-4567'),
            $this->normalizer->normalize('15551234567'),
        );
    }

    #[Test]
    public function unparseable_values_are_stable_and_do_not_throw(): void
    {
        $this->assertSame('', $this->normalizer->normalize(''));
        $this->assertSame('', $this->normalizer->normalize(null));
        $this->assertSame($this->normalizer->normalize('abc'), $this->normalizer->normalize('ABC'));
        $this->assertSame('d:123', $this->normalizer->normalize('123'));
        $this->assertSame('d:123', $this->normalizer->normalize('1-2-3'));
    }

    #[Test]
    public function display_keeps_the_original_trimmed_value(): void
    {
        $this->assertSame('+1 555 123-4567', $this->normalizer->display(' +1 555 123-4567 '));
        $this->assertNull($this->normalizer->display(''));
    }
}
