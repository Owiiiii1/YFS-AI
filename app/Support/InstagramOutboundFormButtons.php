<?php

namespace App\Support;

final class InstagramOutboundFormButtons
{
    public const BUTTON_TITLE_MAX = 20;

    public const CARD_TITLE_MAX = 80;

    public const CARD_SUBTITLE_MAX = 80;

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    public static function urlsIn(string $text, array $config = []): array
    {
        $urls = [];

        foreach (BotFormLinks::fromConfig($config) as $link) {
            $canonical = trim((string) ($link['url'] ?? ''));
            $needle = rtrim($canonical, '/');
            if ($needle !== '' && str_contains($text, $needle) && ! in_array($canonical, $urls, true)) {
                $urls[] = $canonical;
            }
        }

        if (preg_match_all('#https://form\.youngfashionshow\.com/[^\s<>"\']+#i', $text, $matches) > 0) {
            foreach ($matches[0] as $raw) {
                $clean = rtrim((string) $raw, '.,);]');
                if ($clean === '') {
                    continue;
                }
                foreach ($urls as $existing) {
                    if (rtrim($existing, '/') === rtrim($clean, '/')) {
                        continue 2;
                    }
                }
                $urls[] = $clean;
            }
        }

        return $urls;
    }

    /**
     * @param  list<string>  $urls
     */
    public static function strip(string $text, array $urls): string
    {
        $stripped = $text;
        foreach ($urls as $url) {
            $variants = array_unique([
                $url,
                rtrim($url, '/'),
                rtrim($url, '/').'/',
            ]);
            foreach ($variants as $variant) {
                if ($variant !== '') {
                    $stripped = str_ireplace($variant, '', $stripped);
                }
            }
        }

        $stripped = preg_replace('#https://form\.youngfashionshow\.com/[^\s<>"\']+#i', '', $stripped) ?? $stripped;
        $stripped = preg_replace("/[ \t]+\n/", "\n", $stripped) ?? $stripped;
        $stripped = preg_replace("/\n{3,}/", "\n\n", $stripped) ?? $stripped;

        return trim($stripped);
    }

    /**
     * @param  array{id?:string,labels?:array{en?:string,ru?:string,uk?:string},url?:string}|null  $matchedLink
     * @return array{title: string, subtitle: string, button: string}
     */
    public static function copy(string $locale, ?array $matchedLink = null): array
    {
        $locale = in_array($locale, ['en', 'ru', 'uk'], true) ? $locale : 'en';

        $button = match ($locale) {
            'en' => 'Open form',
            'uk' => 'Відкрити форму',
            default => 'Открыть форму',
        };

        $title = match ($locale) {
            'en' => 'Application form',
            default => 'Форма заявки',
        };

        $subtitle = trim((string) (data_get($matchedLink, "labels.{$locale}") ?: 'Young Fashion Show'));

        return [
            'title' => mb_substr($title, 0, self::CARD_TITLE_MAX),
            'subtitle' => mb_substr($subtitle, 0, self::CARD_SUBTITLE_MAX),
            'button' => mb_substr($button, 0, self::BUTTON_TITLE_MAX),
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{id:string,labels:array{en:string,ru:string,uk:string},url:string}|null
     */
    public static function matchLink(string $url, array $config = []): ?array
    {
        $needle = rtrim($url, '/');
        foreach (BotFormLinks::fromConfig($config) as $link) {
            if (rtrim((string) $link['url'], '/') === $needle) {
                return $link;
            }
        }

        return null;
    }
}
