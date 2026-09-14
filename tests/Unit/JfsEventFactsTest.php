<?php

namespace Tests\Unit;

use App\Services\Jfs\JfsReadService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class JfsEventFactsTest extends TestCase
{
    private JfsReadService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new JfsReadService();
    }

    #[Test]
    public function a_hidden_date_is_never_exposed(): void
    {
        $block = $this->service->eventsFactBlock([[
            'name' => 'YFS CHICAGO',
            'city' => 'Chicago',
            'location' => 'Illinois',
            'starts_at' => null,
            'ends_at' => null,
            'date_announced' => false,
            'is_past' => false,
            'description' => null,
        ]]);

        $this->assertStringContainsString('NOT ANNOUNCED YET', $block);
        $this->assertStringNotContainsString('2026-12-06', $block);
    }

    #[Test]
    public function past_shows_are_separated_from_upcoming_ones(): void
    {
        $block = $this->service->eventsFactBlock([
            [
                'name' => 'YFS MIAMI',
                'city' => 'Miami',
                'location' => 'Miami',
                'starts_at' => '2020-04-26 00:00:00',
                'ends_at' => null,
                'date_announced' => true,
                'is_past' => true,
                'description' => null,
            ],
            [
                'name' => 'YFS NEW YORK',
                'city' => 'New York',
                'location' => 'NY',
                'starts_at' => '2099-03-07 00:00:00',
                'ends_at' => null,
                'date_announced' => true,
                'is_past' => false,
                'description' => null,
            ],
        ]);

        $upcoming = strpos($block, 'UPCOMING SHOWS');
        $past = strpos($block, 'PAST SHOWS');

        $this->assertNotFalse($upcoming);
        $this->assertNotFalse($past);
        $this->assertLessThan($past, $upcoming);
        $this->assertStringContainsString('2020-04-26', substr($block, $past));
        $this->assertStringContainsString('2099-03-07', substr($block, $upcoming, $past - $upcoming));
        $this->assertStringNotContainsString('00:00:00', $block);
    }

    #[Test]
    public function all_dates_pending_tells_the_bot_to_say_they_are_being_confirmed(): void
    {
        $block = $this->service->eventsFactBlock([[
            'name' => 'YFS MIAMI',
            'city' => 'Miami',
            'location' => 'Miami',
            'starts_at' => '2020-04-26 00:00:00',
            'ends_at' => null,
            'date_announced' => true,
            'is_past' => true,
            'description' => null,
        ]]);

        $this->assertStringContainsString('UPCOMING SHOWS: none with a confirmed date yet', $block);
    }

    #[Test]
    public function brand_lineups_hide_unannounced_dates_too(): void
    {
        $block = $this->service->brandsFactBlock([[
            'name' => 'YFS CHICAGO',
            'city' => 'Chicago',
            'starts_at' => null,
            'date_announced' => false,
            'is_past' => false,
            'brand_count' => 0,
            'brands' => [],
        ]]);

        $this->assertStringContainsString('NOT ANNOUNCED YET', $block);
        $this->assertStringContainsString('lineup not published yet', $block);
    }
}
