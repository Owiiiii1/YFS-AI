<?php

namespace Tests\Unit\Identity;

use App\Services\Identity\IdentityNameMatcher;
use App\Services\Jfs\JfsIdentityMatch;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IdentityNameMatcherTest extends TestCase
{
    #[Test]
    public function latin_exact_and_hyphen_folding_remain_whole_word(): void
    {
        $this->assertTrue(JfsIdentityMatch::nameMatches('Anna Ivanova', 'anna ivanova'));
        $this->assertTrue(JfsIdentityMatch::nameMatches('Anna Ivanova', 'Ivanova Anna'));
        $this->assertTrue(JfsIdentityMatch::nameMatches('Mary-Jane Smith', 'Mary Jane Smith'));
        $this->assertFalse(JfsIdentityMatch::nameMatches('Anna Ivanova', 'Ann'));
        $this->assertFalse(IdentityNameMatcher::matchesVariant('Anna Ivanova', 'Ann'));
    }

    #[Test]
    public function cyrillic_ru_and_ua_match_latin_stored_names(): void
    {
        $this->assertTrue(IdentityNameMatcher::matchesVariant('Yevheniia Kovalenko', 'Евгения Коваленко'));
        $this->assertTrue(IdentityNameMatcher::matchesVariant('Yevheniia Kovalenko', 'Євгенія Коваленко'));
        $this->assertTrue(IdentityNameMatcher::matchesVariant('Oleksandr Petrenko', 'Александр Петренко'));
        $this->assertTrue(IdentityNameMatcher::matchesVariant('Oleksandr Petrenko', 'Олександр Петренко'));
        $this->assertTrue(IdentityNameMatcher::matchesVariant('Yuliia Bondarenko', 'Юлия Бондаренко'));
        $this->assertTrue(IdentityNameMatcher::matchesVariant('Yuliia Bondarenko', 'Юлія Бондаренко'));
        $this->assertFalse(IdentityNameMatcher::matchesExact('Yevheniia Kovalenko', 'Евгения Коваленко'));
    }

    #[Test]
    public function common_transliteration_and_language_forms_share_keys(): void
    {
        $this->assertTrue(IdentityNameMatcher::matchesVariant('Yevheniia Kovalenko', 'Yevgeniya Kovalenko'));
        $this->assertTrue(IdentityNameMatcher::matchesVariant('Yevheniia Kovalenko', 'Evgenia Kovalenko'));
        $this->assertTrue(IdentityNameMatcher::matchesVariant('Oleksandr Petrenko', 'Alexander Petrenko'));
        $this->assertTrue(IdentityNameMatcher::matchesVariant('Yuliia Bondarenko', 'Julia Bondarenko'));
        $this->assertTrue(IdentityNameMatcher::matchesVariant('Kateryna Shevchenko', 'Екатерина Шевченко'));
        $this->assertTrue(IdentityNameMatcher::matchesVariant('Kateryna Shevchenko', 'Катерина Шевченко'));
        $this->assertTrue(IdentityNameMatcher::matchesVariant('Leo', 'Лев'));
        $this->assertTrue(IdentityNameMatcher::matchesVariant('Mia', 'Мия'));
    }

    #[Test]
    public function similar_but_distinct_names_do_not_share_keys(): void
    {
        $this->assertFalse(IdentityNameMatcher::matchesVariant('Yevheniia Kovalenko', 'Евгений Коваленко'));
        $this->assertFalse(IdentityNameMatcher::matchesVariant('Yuliia Bondarenko', 'Юліана Бондаренко'));
        $this->assertFalse(IdentityNameMatcher::matchesVariant('Yuliana Bondarenko', 'Юлия Бондаренко'));
    }

    #[Test]
    public function bitrix_latin_query_uses_canonical_alias_not_cyrillic(): void
    {
        $this->assertSame('yevheniia kovalenko', IdentityNameMatcher::primaryLatin('Евгения Коваленко'));
        $this->assertSame('oleksandr petrenko', IdentityNameMatcher::primaryLatin('Александр Петренко'));
        $this->assertSame('yuliia bondarenko', IdentityNameMatcher::primaryLatin('Юлія Бондаренко'));
        $this->assertSame('olga petrova', IdentityNameMatcher::primaryLatin('Olga Petrova'));
    }
}
