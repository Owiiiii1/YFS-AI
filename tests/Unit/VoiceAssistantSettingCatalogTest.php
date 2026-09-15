<?php

namespace Tests\Unit;

use App\Support\VoiceAssistantSettingCatalog;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VoiceAssistantSettingCatalogTest extends TestCase
{
    #[Test]
    public function catalog_has_the_required_policy_sections_without_invented_facts(): void
    {
        $sections = VoiceAssistantSettingCatalog::sections();
        $keys = array_column($sections, 'key');

        $this->assertSame(VoiceAssistantSettingCatalog::KEYS, $keys);

        $byKey = [];
        foreach ($sections as $section) {
            $byKey[$section['key']] = $section['instructions'];
        }

        $this->assertStringContainsString('Self-Service First', $byKey['service_tiers']);
        $this->assertStringContainsString('Priority Personal Support', $byKey['service_tiers']);
        $this->assertStringContainsString('SALE → CONTRACT → CUSTOMER SUPPORT', $byKey['sales_support']);
        $this->assertStringContainsString('upgrade package', $byKey['sales_support']);
        $this->assertStringNotContainsString('12:00', $byKey['rehearsals']);
        $this->assertStringNotContainsString('parking lot', strtolower($byKey['show_day']));
    }
}
