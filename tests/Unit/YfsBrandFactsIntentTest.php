<?php

namespace Tests\Unit;

use App\Services\Bot\YfsIntentRouter;
use App\Services\Jfs\JfsReadService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class YfsBrandFactsIntentTest extends TestCase
{
    #[Test]
    public function parent_brand_questions_load_show_brands_not_designer_leads(): void
    {
        $router = new YfsIntentRouter();

        $route = $router->route('Интересно какие бренды одежды будут показывать');

        $this->assertTrue($route['wants_brands']);
        $this->assertContains('show_brands', $route['topics']);
        $this->assertNotContains('designers', $route['topics']);
    }

    #[Test]
    public function past_event_brand_question_is_informational(): void
    {
        $router = new YfsIntentRouter();

        $route = $router->route('А какие бренды на прошлых иаентах были?');

        $this->assertTrue($route['wants_brands']);
        $this->assertContains('show_brands', $route['topics']);
    }

    #[Test]
    public function designer_partnership_is_still_a_lead(): void
    {
        $router = new YfsIntentRouter();

        $route = $router->route('У нас бренд детской одежды, хотим участвовать');

        $this->assertFalse($route['wants_brands']);
        $this->assertContains('designers', $route['topics']);
        $this->assertNotContains('show_brands', $route['topics']);
    }

    #[Test]
    public function brand_fact_block_lists_past_counts_and_names(): void
    {
        $block = (new JfsReadService())->brandsFactBlock([
            [
                'name' => 'YFS Miami',
                'city' => 'Miami',
                'starts_at' => '2026-04-26 00:00:00',
                'is_past' => true,
                'brand_count' => 2,
                'brands' => ['Bebeelegante', 'HOOLA'],
            ],
            [
                'name' => 'YFS Chicago',
                'city' => 'Chicago',
                'starts_at' => '2026-10-11 00:00:00',
                'is_past' => false,
                'brand_count' => 0,
                'brands' => [],
            ],
        ]);

        $this->assertStringContainsString('LIVE BRAND FACTS', $block);
        $this->assertStringContainsString('PAST SHOWS', $block);
        $this->assertStringContainsString('Bebeelegante', $block);
        $this->assertStringContainsString('brands: 2', $block);
        $this->assertStringContainsString('lineup not published yet', $block);
    }
}
