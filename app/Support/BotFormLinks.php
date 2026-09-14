<?php

namespace App\Support;

final class BotFormLinks
{
    /**
     * @return list<array{id:string,labels:array{en:string,ru:string,uk:string},url:string}>
     */
    public static function defaults(): array
    {
        return [
            [
                'id' => 'participant',
                'labels' => [
                    'en' => 'Participant / parent',
                    'ru' => 'Участник / родитель',
                    'uk' => 'Учасник / батьки',
                ],
                'url' => 'https://form.youngfashionshow.com/crm_form_ppigu/',
            ],
            [
                'id' => 'team_partner',
                'labels' => [
                    'en' => 'Team / specialist / partner',
                    'ru' => 'Команда / специалист / партнёр',
                    'uk' => 'Команда / спеціаліст / партнер',
                ],
                'url' => 'https://form.youngfashionshow.com/crm_form_e5l3g/',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<array{id:string,labels:array{en:string,ru:string,uk:string},url:string}>
     */
    public static function fromConfig(array $config): array
    {
        $values = is_array($config['business_values'] ?? null) ? $config['business_values'] : [];
        $raw = $values['form_links'] ?? null;
        if (! is_array($raw) || $raw === []) {
            $links = self::defaults();
            $participant = trim((string) ($values['participant_form_url'] ?? ''));
            $team = trim((string) ($values['team_partner_form_url'] ?? ''));
            if ($participant !== '') {
                $links[0]['url'] = $participant;
            }
            if ($team !== '') {
                $links[1]['url'] = $team;
            }

            return $links;
        }

        $links = [];
        foreach (array_values($raw) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($row['id'] ?? ''))) ?: 'form_'.count($links);
            $url = trim((string) ($row['url'] ?? ''));
            if ($url === '') {
                continue;
            }
            $links[] = [
                'id' => $id,
                'labels' => [
                    'en' => (string) (data_get($row, 'labels.en') ?: ($row['label'] ?? $id)),
                    'ru' => (string) (data_get($row, 'labels.ru') ?: data_get($row, 'labels.en') ?: ($row['label'] ?? $id)),
                    'uk' => (string) (data_get($row, 'labels.uk') ?: data_get($row, 'labels.en') ?: ($row['label'] ?? $id)),
                ],
                'url' => $url,
            ];
        }

        return $links !== [] ? $links : self::defaults();
    }

    /**
     * @param  list<array<string, mixed>>  $links
     * @return list<array{id:string,labels:array{en:string,ru:string,uk:string},url:string}>
     */
    public static function normalize(array $links): array
    {
        return self::fromConfig([
            'business_values' => ['form_links' => $links],
        ]);
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  list<array<string, mixed>>  $links
     * @return array<string, mixed>
     */
    public static function applyToBusinessValues(array $values, array $links): array
    {
        $normalized = self::normalize($links);
        $values['form_links'] = $normalized;
        foreach ($normalized as $link) {
            $values[$link['id'].'_form_url'] = $link['url'];
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, string>
     */
    public static function placeholderValues(array $config): array
    {
        $values = [];
        foreach (self::fromConfig($config) as $link) {
            $values[$link['id'].'_form_url'] = $link['url'];
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  list<string>  $excludeIds
     */
    public static function factBlock(array $config, array $excludeIds = []): string
    {
        $links = self::fromConfig($config);
        if ($excludeIds !== []) {
            $links = array_values(array_filter(
                $links,
                fn (array $link): bool => ! in_array((string) ($link['id'] ?? ''), $excludeIds, true),
            ));
        }
        if ($links === []) {
            return '';
        }

        $lines = ['OFFICIAL FORM LINKS — send these exact URLs when a form is needed. Do not invent other form URLs.'];
        foreach ($links as $link) {
            $lines[] = '- '.$link['labels']['en'].': '.$link['url'];
        }

        return implode("\n", $lines);
    }
}
