<?php

namespace App\Services\Identity;

final class IdentityNameMatcher
{
    private const MAX_VARIANTS_PER_WORD = 24;

    /**
     * Latin forms that simple character transliteration cannot produce.
     *
     * @var list<list<string>>
     */
    private const ALIAS_GROUPS = [
        ['yevheniia', 'yevhenia', 'yevheniya', 'yevgeniya', 'yevgenia', 'evgenia', 'evgeniya', 'eugenia'],
        ['oleksandr', 'aleksandr', 'alexander'],
        ['yuliia', 'yulia', 'yuliya', 'julia', 'iulia'],
        ['kateryna', 'katerina', 'ekaterina', 'yekaterina', 'catherine'],
        ['oleksandra', 'aleksandra', 'alexandra'],
        ['mykhailo', 'mikhail', 'michael'],
        ['volodymyr', 'vladimir'],
        ['dmytro', 'dmitry', 'dmitriy', 'dmitri'],
        ['serhii', 'sergey', 'sergei', 'sergiy'],
        ['iryna', 'irina'],
        ['tetiana', 'tatiana', 'tatyana'],
        ['sofia', 'sofya', 'sophia', 'sofiia'],
        ['mariia', 'maria', 'mariya'],
        ['nataliia', 'natalia', 'natalya'],
        ['anastasiia', 'anastasia'],
        ['viktoriia', 'victoria', 'viktoria'],
        ['olena', 'yelena', 'elena', 'helen'],
        ['petro', 'peter', 'petr'],
        ['andrii', 'andriy', 'andrei', 'andrey', 'andrew'],
        ['mykola', 'nikolai', 'nicholas'],
        ['ihor', 'igor'],
        ['denys', 'denis'],
        ['maksym', 'maksim', 'maxim'],
        ['oleksii', 'oleksiy', 'alexey', 'aleksei', 'alexei'],
        ['leo', 'lev'],
        ['mia', 'miia'],
    ];

    /**
     * @var array<string, list<string>>
     */
    private static array $aliasIndex = [];

    /**
     * @var array<string, list<string>>
     */
    private static array $keyCache = [];

    /**
     * @return list<string>
     */
    public static function words(string $value): array
    {
        return IdentityNameNormalizer::words($value);
    }

    public static function matchesExact(string $storedName, string $query): bool
    {
        return self::wordsAlign(self::words($storedName), self::words($query), false);
    }

    public static function matchesVariant(string $storedName, string $query): bool
    {
        if (self::matchesExact($storedName, $query)) {
            return true;
        }

        return self::wordsAlign(self::words($storedName), self::words($query), true);
    }

    /**
     * One Latin query suitable for a controlled Bitrix %NAME lookup.
     */
    public static function primaryLatin(string $name): string
    {
        $latinWords = [];
        foreach (self::words($name) as $word) {
            $latinWords[] = self::preferredLatinWord($word);
        }

        return trim(implode(' ', $latinWords));
    }

    private static function preferredLatinWord(string $word): string
    {
        if (! IdentityNameNormalizer::containsCyrillic($word)) {
            return $word;
        }

        $index = self::aliasIndex();
        foreach (self::keys($word) as $key) {
            if (isset($index[$key][0])) {
                return $index[$key][0];
            }
        }

        foreach (self::transliterate($word) as $latin) {
            return $latin;
        }

        return $word;
    }

    /**
     * @param  list<string>  $storedWords
     * @param  list<string>  $queryWords
     */
    private static function wordsAlign(array $storedWords, array $queryWords, bool $variants): bool
    {
        if ($storedWords === [] || $queryWords === []) {
            return false;
        }

        $used = [];
        foreach ($queryWords as $queryWord) {
            if (mb_strlen($queryWord) < 2) {
                return false;
            }
            $matched = false;
            foreach ($storedWords as $index => $storedWord) {
                if (isset($used[$index])) {
                    continue;
                }
                $ok = $variants
                    ? self::wordsShareKey($storedWord, $queryWord)
                    : $storedWord === $queryWord;
                if (! $ok) {
                    continue;
                }
                $used[$index] = true;
                $matched = true;
                break;
            }
            if (! $matched) {
                return false;
            }
        }

        return true;
    }

    private static function wordsShareKey(string $left, string $right): bool
    {
        if ($left === $right) {
            return true;
        }

        return array_intersect(self::keys($left), self::keys($right)) !== [];
    }

    /**
     * @return list<string>
     */
    public static function keys(string $word): array
    {
        $word = IdentityNameNormalizer::fold($word);
        if ($word === '' || isset(self::$keyCache[$word])) {
            return $word === '' ? [] : self::$keyCache[$word];
        }

        $keys = [$word];
        if (IdentityNameNormalizer::containsCyrillic($word)) {
            foreach (self::transliterate($word) as $latin) {
                $keys[] = $latin;
            }
        }
        $keys = self::expandAliases($keys);
        $keys = array_values(array_unique(array_filter(
            $keys,
            fn (string $key): bool => $key !== '' && mb_strlen($key) >= 2,
        )));
        sort($keys);
        self::$keyCache[$word] = $keys;

        return $keys;
    }

    /**
     * @param  list<string>  $keys
     * @return list<string>
     */
    private static function expandAliases(array $keys): array
    {
        $index = self::aliasIndex();
        $extra = [];
        foreach ($keys as $key) {
            foreach ($index[$key] ?? [] as $alias) {
                $extra[] = $alias;
            }
        }

        return array_merge($keys, $extra);
    }

    /**
     * @return array<string, list<string>>
     */
    private static function aliasIndex(): array
    {
        if (self::$aliasIndex !== []) {
            return self::$aliasIndex;
        }

        $index = [];
        foreach (self::ALIAS_GROUPS as $group) {
            foreach ($group as $member) {
                $index[$member] = $group;
            }
        }
        self::$aliasIndex = $index;

        return self::$aliasIndex;
    }

    /**
     * @return list<string>
     */
    private static function transliterate(string $word): array
    {
        $chars = preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY);
        if (! is_array($chars) || $chars === []) {
            return [];
        }

        $variants = [''];
        foreach ($chars as $i => $char) {
            $options = self::latinOptions($char, $i === 0);
            $next = [];
            foreach ($variants as $prefix) {
                foreach ($options as $option) {
                    $next[] = $prefix.$option;
                    if (count($next) >= self::MAX_VARIANTS_PER_WORD) {
                        break 2;
                    }
                }
            }
            $variants = $next !== [] ? $next : $variants;
        }

        $variants = array_values(array_unique(array_filter(
            $variants,
            fn (string $value): bool => $value !== '' && ! preg_match('/\p{Cyrillic}/u', $value),
        )));

        return $variants;
    }

    /**
     * @return list<string>
     */
    private static function latinOptions(string $char, bool $isStart): array
    {
        $map = [
            'а' => ['a'],
            'б' => ['b'],
            'в' => ['v'],
            'г' => ['h', 'g'],
            'д' => ['d'],
            'е' => $isStart ? ['ye', 'e'] : ['e'],
            'є' => ['ye', 'ie', 'e'],
            'ж' => ['zh'],
            'з' => ['z'],
            'и' => ['i', 'y'],
            'і' => ['i'],
            'ї' => ['yi', 'i'],
            'й' => ['i', 'y'],
            'к' => ['k'],
            'л' => ['l'],
            'м' => ['m'],
            'н' => ['n'],
            'о' => ['o'],
            'п' => ['p'],
            'р' => ['r'],
            'с' => ['s'],
            'т' => ['t'],
            'у' => ['u'],
            'ф' => ['f'],
            'х' => ['kh', 'h'],
            'ц' => ['ts'],
            'ч' => ['ch'],
            'ш' => ['sh'],
            'щ' => ['shch', 'sch'],
            'ь' => [''],
            'ъ' => [''],
            'ы' => ['y'],
            'э' => ['e'],
            'ю' => $isStart ? ['yu', 'iu'] : ['iu', 'yu', 'ju'],
            'я' => $isStart ? ['ya', 'ia'] : ['ia', 'ya', 'ja'],
        ];

        if (isset($map[$char])) {
            return $map[$char];
        }
        if (! preg_match('/\p{Cyrillic}/u', $char)) {
            return [$char];
        }

        return [''];
    }
}
