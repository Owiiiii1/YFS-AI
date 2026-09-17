<?php

namespace Tests\Unit\Voice;

use App\Services\Voice\Identity\CustomerIdentityResolver;
use App\Services\Voice\Identity\CustomerIdentityResult;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeJfsReadService;
use Tests\TestCase;

class CustomerIdentityResolverMultilingualTest extends TestCase
{
    private FakeJfsReadService $jfs;

    private CustomerIdentityResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->jfs = new FakeJfsReadService;
        $this->jfs->clients = [
            [
                'id' => 31,
                'name' => 'Yevheniia Kovalenko',
                'language' => 'uk',
                'phone' => '+1-555-301-0001',
                'children' => ['Sofiia'],
            ],
            [
                'id' => 32,
                'name' => 'Oleksandr Petrenko',
                'language' => 'uk',
                'phone' => '+1-555-302-0002',
                'children' => ['Leo'],
            ],
            [
                'id' => 33,
                'name' => 'Yuliia Bondarenko',
                'language' => 'uk',
                'phone' => '+1-555-303-0003',
                'children' => ['Mia'],
            ],
            [
                'id' => 34,
                'name' => 'Yuliana Bondarenko',
                'language' => 'en',
                'phone' => '+1-555-304-0004',
                'children' => ['Sam'],
            ],
            [
                'id' => 35,
                'name' => 'Kateryna Shevchenko',
                'language' => 'uk',
                'phone' => '+1-555-305-0005',
                'children' => ['Denys'],
            ],
            [
                'id' => 13,
                'name' => 'Olga Petrova',
                'language' => 'uk',
                'phone' => '+1-555-222-0002',
                'children' => ['Mia'],
            ],
            [
                'id' => 11,
                'name' => 'Anna Ivanova',
                'language' => 'ru',
                'phone' => '+1-555-111-0001',
                'children' => ['Mia'],
            ],
            [
                'id' => 14,
                'name' => 'Maria Ivanova',
                'language' => 'en',
                'phone' => '+1-555-444-0004',
                'children' => ['Sam'],
            ],
        ];
        $this->resolver = new CustomerIdentityResolver($this->jfs);
    }

    #[Test]
    public function exact_latin_name_still_resolves_unique(): void
    {
        $result = $this->resolver->resolveBySpokenHints('Olga Petrova');

        $this->assertSame(CustomerIdentityResult::UNIQUE, $result->status);
        $this->assertSame(13, $result->yfsAppUserId);
        $this->assertSame('name', $result->matchMethod);
        $this->assertSame(0, $this->jfs->findClientsByNameVariantCalls);
    }

    #[Test]
    public function cyrillic_ru_to_latin_stored_name_is_not_unique_without_extra_evidence(): void
    {
        $result = $this->resolver->resolveBySpokenHints('Евгения Коваленко');

        $this->assertSame(CustomerIdentityResult::AMBIGUOUS, $result->status);
        $this->assertSame(1, $result->matchCount);
        $this->assertNull($result->yfsAppUserId);
        $this->assertSame('name_variant', $result->matchMethod);
    }

    #[Test]
    public function cyrillic_ua_to_latin_stored_name_is_not_unique_without_extra_evidence(): void
    {
        $result = $this->resolver->resolveBySpokenHints('Олександр Петренко');

        $this->assertSame(CustomerIdentityResult::AMBIGUOUS, $result->status);
        $this->assertSame(1, $result->matchCount);
        $this->assertNull($result->yfsAppUserId);
    }

    #[Test]
    public function transliteration_variants_are_candidates_only(): void
    {
        foreach (['Yevgeniya Kovalenko', 'Evgenia Kovalenko', 'Alexander Petrenko', 'Julia Bondarenko'] as $query) {
            $result = $this->resolver->resolveBySpokenHints($query);
            $this->assertSame(CustomerIdentityResult::AMBIGUOUS, $result->status, $query);
            $this->assertNull($result->yfsAppUserId, $query);
        }
    }

    #[Test]
    public function child_name_cyrillic_to_latin_plus_parent_variant_can_be_unique(): void
    {
        $result = $this->resolver->resolveBySpokenHints('Александр Петренко', 'Лев');

        $this->assertSame(CustomerIdentityResult::UNIQUE, $result->status);
        $this->assertSame(32, $result->yfsAppUserId);
        $this->assertSame('Oleksandr Petrenko', $result->displayName);
        $this->assertSame('name_and_child', $result->matchMethod);
        $encoded = json_encode($result) ?: '';
        $this->assertStringNotContainsString('Лев', $encoded);
        $this->assertStringNotContainsString('Leo', $encoded);
    }

    #[Test]
    public function first_last_order_and_hyphens_still_match_exact_latin(): void
    {
        $this->jfs->clients = [[
            'id' => 41,
            'name' => 'Mary-Jane Smith',
            'language' => 'en',
            'phone' => '+1-555-401-0001',
            'children' => [],
        ]];

        $reversed = $this->resolver->resolveBySpokenHints('Smith Mary Jane');
        $hyphen = $this->resolver->resolveBySpokenHints('Mary Jane Smith');

        $this->assertSame(CustomerIdentityResult::UNIQUE, $reversed->status);
        $this->assertSame(41, $reversed->yfsAppUserId);
        $this->assertSame(CustomerIdentityResult::UNIQUE, $hyphen->status);
        $this->assertSame(41, $hyphen->yfsAppUserId);
    }

    #[Test]
    public function two_plausible_multilingual_candidates_are_ambiguous(): void
    {
        $this->jfs->clients[] = [
            'id' => 36,
            'name' => 'Evgenia Kovalenko',
            'language' => 'en',
            'phone' => '+1-555-306-0006',
            'children' => ['Mia'],
        ];

        $result = $this->resolver->resolveBySpokenHints('Евгения Коваленко');

        $this->assertSame(CustomerIdentityResult::AMBIGUOUS, $result->status);
        $this->assertSame(2, $result->matchCount);
        $this->assertNull($result->yfsAppUserId);
        $this->assertNull($result->displayName);
    }

    #[Test]
    public function similar_names_must_not_become_unique(): void
    {
        $ann = $this->resolver->resolveBySpokenHints('Ann Ivanova');
        $yevhenii = $this->resolver->resolveBySpokenHints('Евгений Коваленко');
        $yuliana = $this->resolver->resolveBySpokenHints('Юлия Бондаренко');

        $this->assertSame(CustomerIdentityResult::NOT_FOUND, $ann->status);
        $this->assertNull($ann->yfsAppUserId);
        $this->assertSame(CustomerIdentityResult::NOT_FOUND, $yevhenii->status);
        $this->assertNull($yevhenii->yfsAppUserId);
        $this->assertSame(CustomerIdentityResult::AMBIGUOUS, $yuliana->status);
        $this->assertSame(1, $yuliana->matchCount);
        $this->assertNull($yuliana->yfsAppUserId);
        $this->assertNotSame(34, $yuliana->yfsAppUserId);
    }

    #[Test]
    public function trusted_phone_plus_multilingual_name_can_resolve_unique(): void
    {
        $result = $this->resolver->resolveBySpokenHints('Евгения Коваленко', null, '+15553010001');

        $this->assertSame(CustomerIdentityResult::UNIQUE, $result->status);
        $this->assertSame(31, $result->yfsAppUserId);
        $this->assertSame('Yevheniia Kovalenko', $result->displayName);
        $this->assertSame('name_and_phone', $result->matchMethod);
        $encoded = json_encode($result) ?: '';
        $this->assertStringNotContainsString('555', $encoded);
        $this->assertStringNotContainsString('+1-', $encoded);
    }

    #[Test]
    public function existing_english_ambiguous_name_behavior_is_unchanged(): void
    {
        $ambiguous = $this->resolver->resolveBySpokenHints('Ivanova');
        $resolved = $this->resolver->resolveBySpokenHints('Ivanova', 'Mia');

        $this->assertSame(CustomerIdentityResult::AMBIGUOUS, $ambiguous->status);
        $this->assertNull($ambiguous->displayName);
        $this->assertSame(CustomerIdentityResult::UNIQUE, $resolved->status);
        $this->assertSame(11, $resolved->yfsAppUserId);
    }
}
