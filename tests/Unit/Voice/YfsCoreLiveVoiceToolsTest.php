<?php

namespace Tests\Unit\Voice;

use App\Services\Voice\Tools\GetPublicShowsVoiceTool;
use App\Services\Voice\Tools\GetShowBrandsVoiceTool;
use App\Services\Voice\Tools\YfsCoreLiveToolSupport;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeJfsReadService;
use Tests\TestCase;

class YfsCoreLiveVoiceToolsTest extends TestCase
{
    #[Test]
    public function public_shows_uses_jfs_read_service_and_keeps_unannounced_dates_null(): void
    {
        $jfs = new FakeJfsReadService;
        $jfs->events = [
            [
                'name' => 'YFS CHICAGO',
                'city' => 'Chicago',
                'location' => 'Illinois',
                'starts_at' => '2026-12-06 00:00:00',
                'ends_at' => '2026-12-07 00:00:00',
                'date_announced' => false,
                'is_past' => false,
                'description' => 'Public description',
            ],
            [
                'name' => 'YFS MIAMI',
                'city' => 'Miami',
                'location' => 'Miami Beach',
                'starts_at' => '2099-03-07 00:00:00',
                'ends_at' => null,
                'date_announced' => true,
                'is_past' => false,
                'description' => null,
            ],
        ];

        $result = (new GetPublicShowsVoiceTool($jfs))->execute([]);

        $this->assertSame(1, $jfs->publicEventsCalls);
        $this->assertSame(0, $jfs->writeCalls);
        $this->assertSame(0, $jfs->findClientByEmailCalls);
        $this->assertTrue($result['ok']);
        $this->assertSame(GetPublicShowsVoiceTool::NAME, $result['tool']);
        $this->assertSame('yfs_core', $result['source']);
        $this->assertSame(2, $result['count']);
        $this->assertNull($result['results'][0]['starts_at']);
        $this->assertNull($result['results'][0]['ends_at']);
        $this->assertFalse($result['results'][0]['date_announced']);
        $this->assertSame('2099-03-07 00:00:00', $result['results'][1]['starts_at']);
        $encoded = json_encode($result) ?: '';
        $this->assertStringNotContainsString('2026-12-06', $encoded);
        $this->assertStringNotContainsString('JFS_DB_', $encoded);
        $this->assertStringNotContainsString('password', $encoded);
    }

    #[Test]
    public function public_shows_filters_by_show_name_and_city(): void
    {
        $jfs = new FakeJfsReadService;
        $jfs->events = [
            $this->event('YFS CHICAGO', 'Chicago'),
            $this->event('YFS MIAMI', 'Miami'),
        ];

        $byName = (new GetPublicShowsVoiceTool($jfs))->execute(['show_name' => 'miami']);
        $byCity = (new GetPublicShowsVoiceTool($jfs))->execute(['city' => 'Chicago']);
        $none = (new GetPublicShowsVoiceTool($jfs))->execute(['city' => 'Paris']);

        $this->assertSame(1, $byName['count']);
        $this->assertSame('YFS MIAMI', $byName['results'][0]['name']);
        $this->assertSame(1, $byCity['count']);
        $this->assertSame('YFS CHICAGO', $byCity['results'][0]['name']);
        $this->assertTrue($none['ok']);
        $this->assertSame(0, $none['count']);
        $this->assertSame([], $none['results']);
        $this->assertSame(YfsCoreLiveToolSupport::MESSAGE_NO_MATCHING_SHOWS, $none['message']);
    }

    #[Test]
    public function show_brands_uses_jfs_and_marks_empty_lineup_unpublished(): void
    {
        $jfs = new FakeJfsReadService;
        $jfs->lineups = [
            [
                'name' => 'YFS CHICAGO',
                'city' => 'Chicago',
                'starts_at' => '2026-12-06 00:00:00',
                'date_announced' => false,
                'is_past' => false,
                'brand_count' => 0,
                'brands' => [],
            ],
            [
                'name' => 'YFS MIAMI',
                'city' => 'Miami',
                'starts_at' => '2099-03-07 00:00:00',
                'date_announced' => true,
                'is_past' => false,
                'brand_count' => 2,
                'brands' => ['Alpha', 'Beta'],
            ],
        ];

        $result = (new GetShowBrandsVoiceTool($jfs))->execute(['city' => 'Chicago']);

        $this->assertSame(1, $jfs->publicBrandLineupsCalls);
        $this->assertSame(0, $jfs->writeCalls);
        $this->assertSame(0, $jfs->findClientByEmailCalls);
        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['count']);
        $this->assertSame([], $result['results'][0]['brands']);
        $this->assertSame(0, $result['results'][0]['brand_count']);
        $this->assertFalse($result['results'][0]['lineup_published']);
        $this->assertNull($result['results'][0]['starts_at']);
        $encoded = json_encode($result) ?: '';
        $this->assertStringNotContainsString('2026-12-06', $encoded);
        $this->assertStringNotContainsString('Alpha', $encoded);
    }

    #[Test]
    public function unavailable_jfs_returns_safe_error_contract(): void
    {
        $jfs = new FakeJfsReadService;
        $jfs->configured = false;
        $jfs->events = [$this->event('SHOULD NOT APPEAR', 'Secret City')];
        $jfs->lineups = [[
            'name' => 'SHOULD NOT APPEAR',
            'city' => 'Secret City',
            'starts_at' => null,
            'date_announced' => false,
            'is_past' => false,
            'brand_count' => 1,
            'brands' => ['Leaked'],
        ]];

        $shows = (new GetPublicShowsVoiceTool($jfs))->execute([]);
        $brands = (new GetShowBrandsVoiceTool($jfs))->execute([]);

        $this->assertFalse($shows['ok']);
        $this->assertSame('source_unavailable', $shows['error']);
        $this->assertSame([], $shows['results']);
        $this->assertSame(0, $shows['count']);
        $this->assertFalse($brands['ok']);
        $this->assertSame('source_unavailable', $brands['error']);
        $encoded = json_encode($shows).json_encode($brands);
        $this->assertStringNotContainsString('SHOULD NOT APPEAR', $encoded);
        $this->assertStringNotContainsString('JFS_DB_PASSWORD', $encoded);
        $this->assertStringNotContainsString('127.0.0.1', $encoded);
        $this->assertArrayNotHasKey('exception', $shows);
        $this->assertArrayNotHasKey('trace', $brands);
    }

    /**
     * @return array<string, mixed>
     */
    private function event(string $name, string $city): array
    {
        return [
            'name' => $name,
            'city' => $city,
            'location' => $city,
            'starts_at' => '2099-01-01 00:00:00',
            'ends_at' => null,
            'date_announced' => true,
            'is_past' => false,
            'description' => null,
        ];
    }
}
