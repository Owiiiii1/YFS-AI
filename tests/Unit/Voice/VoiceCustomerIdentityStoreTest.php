<?php

namespace Tests\Unit\Voice;

use App\Models\VoiceContact;
use App\Services\Voice\Identity\CustomerIdentityResult;
use App\Services\Voice\Identity\VoiceCustomerIdentityStore;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VoiceCustomerIdentityStoreTest extends TestCase
{
    #[Test]
    public function compact_unique_identity_omits_sensitive_fields(): void
    {
        $store = new VoiceCustomerIdentityStore;
        $payload = $store->compact(CustomerIdentityResult::unique('phone', 44, 'Test Parent', 'ru'));

        $this->assertSame('unique', $payload['status']);
        $this->assertSame(44, $payload['app_user_id']);
        $this->assertSame('Test Parent', $payload['display_name']);
        $this->assertSame('phone', $payload['match_method']);
        $this->assertArrayHasKey('matched_at', $payload);
        $this->assertArrayNotHasKey('children', $payload);
        $this->assertArrayNotHasKey('email', $payload);
        $this->assertArrayNotHasKey('phone', $payload);
        $this->assertArrayNotHasKey('tickets', $payload);
        $this->assertArrayNotHasKey('payments', $payload);
        $this->assertArrayNotHasKey('contracts', $payload);
    }

    #[Test]
    public function compact_ambiguous_identity_has_no_customer_selection(): void
    {
        $payload = (new VoiceCustomerIdentityStore)->compact(
            CustomerIdentityResult::ambiguous('phone', 3),
        );

        $this->assertSame('ambiguous', $payload['status']);
        $this->assertSame(3, $payload['candidate_count']);
        $this->assertArrayNotHasKey('app_user_id', $payload);
        $this->assertArrayNotHasKey('display_name', $payload);
    }

    #[Test]
    public function unavailable_result_does_not_write_contact_metadata(): void
    {
        $contact = Mockery::mock(VoiceContact::class);
        $contact->shouldNotReceive('forceFill');
        $contact->shouldNotReceive('save');

        (new VoiceCustomerIdentityStore)->remember(
            $contact,
            CustomerIdentityResult::unavailable('phone'),
        );
        $this->assertTrue(true);
    }
}
