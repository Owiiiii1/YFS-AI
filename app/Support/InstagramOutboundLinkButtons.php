<?php

namespace App\Support;

final class InstagramOutboundLinkButtons
{
    public const BUTTON_TITLE_MAX = 20;

    public const CARD_TITLE_MAX = 80;

    public const CARD_SUBTITLE_MAX = 80;

    public const DEFAULT_INSTAGRAM_URL = 'https://www.instagram.com/young.fashion.show/';

    public const DEFAULT_YOUTUBE_URL = 'https://www.youtube.com/@YoungFashionShow';

    public const DEFAULT_WEBSITE_URL = 'https://www.youngfashionshow.com/';

    /**
     * @param  array<string, mixed>  $config
     * @param  list<string>  $omitKinds  link kinds that must not become buttons again (never 'form')
     * @return array{plain: string, cards: list<array{title: string, subtitle: string, buttons: list<array{title: string, url: string}>}>}
     */
    public static function fromReply(
        string $text,
        string $locale = 'ru',
        array $config = [],
        bool $omitInstagram = false,
        array $omitKinds = [],
    ): array {
        if ($omitInstagram) {
            $omitKinds[] = 'instagram';
        }
        $omitKinds = array_values(array_diff(array_unique($omitKinds), ['form']));

        $urls = self::httpsUrlsIn($text);
        $stripUrls = $urls;
        $cardUrls = self::withoutKinds($urls, $omitKinds);
        $cardUrls = self::withOfficialVideoLinks($text, $cardUrls, $config, $omitKinds);
        $cardUrls = self::withoutKinds($cardUrls, $omitKinds);
        if ($omitKinds !== []) {
            $official = self::officialUrls($config);
            foreach ($omitKinds as $kind) {
                if (isset($official[$kind])) {
                    $stripUrls = self::pushUrl($stripUrls, $official[$kind]);
                }
            }
        }
        $plain = self::strip($text, $stripUrls);

        return [
            'plain' => $plain,
            'cards' => self::cardsFor($cardUrls, $locale, $config),
        ];
    }

    /**
     * @return list<string>
     */
    public static function httpsUrlsIn(string $text): array
    {
        if (preg_match_all('#https?://[^\s<>"\']+#i', $text, $matches) < 1) {
            return [];
        }

        $urls = [];
        foreach ($matches[0] as $raw) {
            $clean = self::normalizeUrl((string) $raw);
            if ($clean !== '') {
                $urls = self::pushUrl($urls, $clean);
            }
        }

        return $urls;
    }

    /**
     * @param  list<string>  $urls
     * @param  array<string, mixed>  $config
     * @param  list<string>|bool  $omitKinds  legacy bool means "omit Instagram"
     * @return list<string>
     */
    public static function withOfficialVideoLinks(string $text, array $urls, array $config = [], array|bool $omitKinds = []): array
    {
        if (! self::wantsVideoLinks($text, $urls)) {
            return $urls;
        }

        $omitKinds = $omitKinds === true ? ['instagram'] : (is_array($omitKinds) ? $omitKinds : []);
        $official = self::officialUrls($config);

        foreach (['instagram', 'youtube'] as $kind) {
            if (! in_array($kind, $omitKinds, true)) {
                $urls = self::pushUrl($urls, $official[$kind]);
            }
        }

        return $urls;
    }

    /**
     * @param  list<string>  $urls
     * @param  list<string>  $kinds
     * @return list<string>
     */
    private static function withoutKinds(array $urls, array $kinds): array
    {
        if ($kinds === []) {
            return $urls;
        }

        return array_values(array_filter(
            $urls,
            fn (string $url): bool => ! in_array(self::kind($url), $kinds, true),
        ));
    }

    /**
     * @param  list<string>  $urls
     */
    public static function strip(string $text, array $urls): string
    {
        $stripped = $text;
        foreach ($urls as $url) {
            foreach (array_unique([$url, rtrim($url, '/'), rtrim($url, '/').'/']) as $variant) {
                if ($variant !== '') {
                    $stripped = str_ireplace($variant, '', $stripped);
                }
            }
        }

        $stripped = preg_replace('#https?://[^\s<>"\']+#i', '', $stripped) ?? $stripped;
        $stripped = preg_replace('/^(YouTube|Instagram|Сайт|Website|Форма|Form)\s*:?\s*$/mi', '', $stripped) ?? $stripped;
        $stripped = preg_replace("/[ \t]+\n/", "\n", $stripped) ?? $stripped;
        $stripped = preg_replace("/\n{3,}/", "\n\n", $stripped) ?? $stripped;

        return trim($stripped);
    }

    /**
     * @param  list<string>  $urls
     * @param  array<string, mixed>  $config
     * @return list<array{title: string, subtitle: string, buttons: list<array{title: string, url: string}>}>
     */
    public static function cardsFor(array $urls, string $locale, array $config = []): array
    {
        $locale = in_array($locale, ['en', 'ru', 'uk'], true) ? $locale : 'en';
        $form = [];
        $media = [];
        $other = [];

        foreach ($urls as $url) {
            $kind = self::kind($url);
            if ($kind === 'form') {
                $form[] = $url;
            } elseif (in_array($kind, ['instagram', 'youtube'], true)) {
                $media[] = $url;
            } else {
                $other[] = $url;
            }
        }

        $media = self::preferOfficialMediaOrder($media, $config);

        $cards = [];
        foreach (array_chunk($form, 3) as $chunk) {
            $buttons = [];
            foreach ($chunk as $url) {
                $buttons[] = [
                    'title' => self::buttonTitle('form', $locale),
                    'url' => $url,
                ];
            }
            $matched = InstagramOutboundFormButtons::matchLink($chunk[0], $config);
            $subtitle = trim((string) (data_get($matched, "labels.{$locale}") ?: 'Young Fashion Show'));
            $cards[] = [
                'title' => self::cardTitle('form', $locale),
                'subtitle' => mb_substr($subtitle, 0, self::CARD_SUBTITLE_MAX),
                'buttons' => $buttons,
            ];
        }

        if ($media !== []) {
            $buttons = [];
            foreach (array_slice($media, 0, 3) as $url) {
                $buttons[] = [
                    'title' => self::buttonTitle(self::kind($url), $locale),
                    'url' => $url,
                ];
            }
            $cards[] = [
                'title' => self::cardTitle('video', $locale),
                'subtitle' => 'Instagram · YouTube',
                'buttons' => $buttons,
            ];
        }

        foreach (array_chunk($other, 3) as $chunk) {
            $buttons = [];
            foreach ($chunk as $url) {
                $buttons[] = [
                    'title' => self::buttonTitle(self::kind($url), $locale),
                    'url' => $url,
                ];
            }
            $cards[] = [
                'title' => 'Young Fashion Show',
                'subtitle' => self::buttonTitle('other', $locale),
                'buttons' => $buttons,
            ];
        }

        return $cards;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{instagram: string, youtube: string, website: string}
     */
    public static function officialUrls(array $config = []): array
    {
        $values = is_array($config['business_values'] ?? null) ? $config['business_values'] : [];

        return [
            'instagram' => self::normalizeUrl((string) ($values['instagram_url'] ?? self::DEFAULT_INSTAGRAM_URL))
                ?: self::DEFAULT_INSTAGRAM_URL,
            'youtube' => self::normalizeUrl((string) ($values['youtube_url'] ?? self::DEFAULT_YOUTUBE_URL))
                ?: self::DEFAULT_YOUTUBE_URL,
            'website' => self::normalizeUrl((string) ($values['website_url'] ?? self::DEFAULT_WEBSITE_URL))
                ?: self::DEFAULT_WEBSITE_URL,
        ];
    }

    /**
     * @param  list<string>  $urls
     */
    private static function wantsVideoLinks(string $text, array $urls): bool
    {
        foreach ($urls as $url) {
            if (in_array(self::kind($url), ['instagram', 'youtube'], true)) {
                return true;
            }
        }

        $lower = mb_strtolower($text);

        if (preg_match('/youtube|ютуб|youtu\.be/u', $lower)) {
            return true;
        }

        return (bool) preg_match(
            '/(полн(ые|ый|і).{0,60}(запис|шоу)|видео (прошл|наших|показов)|відео (мин|наших|показ)|past[- ]show videos|looks and (shows|videos))/u',
            $lower,
        );
    }

    /**
     * @param  list<string>  $urls
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private static function preferOfficialMediaOrder(array $urls, array $config): array
    {
        $official = self::officialUrls($config);
        $instagram = [];
        $youtube = [];
        $rest = [];

        foreach ($urls as $url) {
            $kind = self::kind($url);
            if ($kind === 'instagram') {
                $instagram = self::pushUrl($instagram, $official['instagram']);
            } elseif ($kind === 'youtube') {
                $youtube = self::pushUrl($youtube, $official['youtube']);
            } else {
                $rest[] = $url;
            }
        }

        return array_values(array_merge($instagram, $youtube, $rest));
    }

    public static function kind(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if (str_contains($host, 'form.youngfashionshow.com')) {
            return 'form';
        }
        if (str_contains($host, 'instagram.com')) {
            return 'instagram';
        }
        if (str_contains($host, 'youtube.com') || str_contains($host, 'youtu.be')) {
            return 'youtube';
        }
        if (str_contains($host, 'youngfashionshow.com')) {
            return 'website';
        }

        return 'other';
    }

    public static function buttonTitle(string $kind, string $locale): string
    {
        $title = match ($kind) {
            'form' => match ($locale) {
                'en' => 'Open form',
                'uk' => 'Відкрити форму',
                default => 'Открыть форму',
            },
            'instagram' => 'Instagram',
            'youtube' => 'YouTube',
            'website' => match ($locale) {
                'en' => 'Website',
                default => 'Сайт',
            },
            default => match ($locale) {
                'en' => 'Open link',
                default => 'Открыть',
            },
        };

        return mb_substr($title, 0, self::BUTTON_TITLE_MAX);
    }

    public static function cardTitle(string $kind, string $locale): string
    {
        $title = match ($kind) {
            'form' => match ($locale) {
                'en' => 'Application form',
                default => 'Форма заявки',
            },
            'video' => match ($locale) {
                'en' => 'Past show videos',
                'uk' => 'Відео минулих шоу',
                default => 'Видео прошлых шоу',
            },
            default => 'Young Fashion Show',
        };

        return mb_substr($title, 0, self::CARD_TITLE_MAX);
    }

    public static function normalizeUrl(string $url): string
    {
        $url = rtrim($url, '.,);]');
        $url = trim($url);

        return $url;
    }

    /**
     * @param  list<string>  $urls
     * @return list<string>
     */
    private static function pushUrl(array $urls, string $url): array
    {
        $url = self::normalizeUrl($url);
        if ($url === '') {
            return $urls;
        }

        foreach ($urls as $existing) {
            if (self::sameUrl($existing, $url)) {
                return $urls;
            }
        }

        $urls[] = $url;

        return $urls;
    }

    private static function sameUrl(string $left, string $right): bool
    {
        return rtrim(mb_strtolower($left), '/') === rtrim(mb_strtolower($right), '/');
    }
}
