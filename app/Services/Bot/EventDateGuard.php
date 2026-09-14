<?php

namespace App\Services\Bot;

use App\Models\Conversation;
use App\Services\Instagram\ConversationLanguageResolver;
use App\Services\Jfs\JfsReadService;
use Carbon\CarbonImmutable;

/**
 * Last line of defence for show dates: the model can repeat a date from its own
 * earlier messages long after that show has passed or its date was hidden in the
 * main project. Any date in an outgoing reply must match a confirmed upcoming show.
 */
class EventDateGuard
{
    /** @var array<string, int> */
    private const MONTHS = [
        // English
        'january' => 1, 'february' => 2, 'march' => 3, 'april' => 4, 'may' => 5, 'june' => 6,
        'july' => 7, 'august' => 8, 'september' => 9, 'october' => 10, 'november' => 11, 'december' => 12,
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'jun' => 6, 'jul' => 7, 'aug' => 8,
        'sep' => 9, 'sept' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
        // Russian
        'января' => 1, 'январе' => 1, 'январь' => 1, 'февраля' => 2, 'феврале' => 2, 'февраль' => 2,
        'марта' => 3, 'марте' => 3, 'март' => 3, 'апреля' => 4, 'апреле' => 4, 'апрель' => 4,
        'мая' => 5, 'мае' => 5, 'май' => 5, 'июня' => 6, 'июне' => 6, 'июнь' => 6,
        'июля' => 7, 'июле' => 7, 'июль' => 7, 'августа' => 8, 'августе' => 8, 'август' => 8,
        'сентября' => 9, 'сентябре' => 9, 'сентябрь' => 9, 'октября' => 10, 'октябре' => 10, 'октябрь' => 10,
        'ноября' => 11, 'ноябре' => 11, 'ноябрь' => 11, 'декабря' => 12, 'декабре' => 12, 'декабрь' => 12,
        // Ukrainian
        'січня' => 1, 'січні' => 1, 'лютого' => 2, 'лютому' => 2, 'березня' => 3, 'березні' => 3,
        'квітня' => 4, 'квітні' => 4, 'травня' => 5, 'травні' => 5, 'червня' => 6, 'червні' => 6,
        'липня' => 7, 'липні' => 7, 'серпня' => 8, 'серпні' => 8, 'вересня' => 9, 'вересні' => 9,
        'жовтня' => 10, 'жовтні' => 10, 'листопада' => 11, 'листопаді' => 11, 'грудня' => 12, 'грудні' => 12,
        // Romanian / Spanish / German extras
        'ianuarie' => 1, 'februarie' => 2, 'martie' => 3, 'aprilie' => 4, 'mai' => 5, 'iunie' => 6,
        'iulie' => 7, 'septembrie' => 9, 'octombrie' => 10, 'noiembrie' => 11, 'decembrie' => 12,
        'enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4, 'mayo' => 5, 'junio' => 6,
        'julio' => 7, 'agosto' => 8, 'septiembre' => 9, 'octubre' => 10, 'noviembre' => 11, 'diciembre' => 12,
        'januar' => 1, 'februar' => 2, 'märz' => 3, 'juni' => 6, 'juli' => 7, 'dezember' => 12,
    ];

    public function __construct(
        private readonly JfsReadService $jfs,
        private readonly ConversationLanguageResolver $languageResolver,
    ) {}

    /**
     * @param  list<array<string, mixed>>|null  $events
     */
    public function sanitize(
        string $reply,
        Conversation $conversation,
        string $customerText = '',
        ?array $events = null,
    ): string {
        if (trim($reply) === '') {
            return $reply;
        }

        $events ??= $this->jfs->publicEvents();
        if ($events === []) {
            return $reply;
        }

        $allowed = $this->confirmedDates($events);
        $lines = preg_split('/\R/u', $reply) ?: [];
        $offending = [];
        foreach ($lines as $index => $line) {
            foreach ($this->dateClaims($line) as $claim) {
                if (! $this->isAllowed($claim, $allowed)) {
                    $offending[$index] = true;
                    break;
                }
            }
        }

        if ($offending === []) {
            return $reply;
        }

        $kept = [];
        foreach ($lines as $index => $line) {
            if (isset($offending[$index])) {
                continue;
            }
            // An intro like "Upcoming shows:" makes no sense once its list is gone.
            if (str_ends_with(rtrim($line), ':') && isset($offending[$index + 1])) {
                continue;
            }
            $kept[] = $line;
        }

        $locale = $this->languageResolver->resolveFromConversation(
            $conversation,
            $customerText !== '' ? $customerText : $reply,
        );

        $text = trim(implode("\n", $kept));
        $schedule = $this->scheduleText($events, $locale);

        return $text === '' ? $schedule : $text."\n\n".$schedule;
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @return list<string>
     */
    private function confirmedDates(array $events): array
    {
        $dates = [];
        foreach ($events as $event) {
            if (($event['is_past'] ?? false) || ! ($event['date_announced'] ?? true)) {
                continue;
            }
            $startsAt = $event['starts_at'] ?? null;
            if (is_string($startsAt) && trim($startsAt) !== '') {
                $dates[] = CarbonImmutable::parse($startsAt)->toDateString();
            }
        }

        return array_values(array_unique($dates));
    }

    /**
     * @param  list<array<string, mixed>>  $events
     */
    private function scheduleText(array $events, string $locale): string
    {
        $confirmed = [];
        $pending = [];
        foreach ($events as $event) {
            if ($event['is_past'] ?? false) {
                continue;
            }
            $city = trim((string) ($event['city'] ?? ''));
            if ($city === '') {
                continue;
            }
            $city = $this->localizeCity($city, $locale);
            $startsAt = $event['starts_at'] ?? null;
            if (($event['date_announced'] ?? true) && is_string($startsAt) && trim($startsAt) !== '') {
                $confirmed[] = $city.' — '.CarbonImmutable::parse($startsAt)->format('d.m.Y');
            } elseif (! in_array($city, $pending, true)) {
                $pending[] = $city;
            }
        }

        $copy = $this->copy($locale);
        $parts = [];
        if ($confirmed !== []) {
            $parts[] = $copy['confirmed'].' '.implode('; ', $confirmed).'.';
        }
        if ($pending !== []) {
            $parts[] = str_replace(':cities', implode(', ', $pending), $copy['pending']);
        }
        if ($parts === []) {
            $parts[] = $copy['none'];
        }

        return implode(' ', $parts);
    }

    private function localizeCity(string $city, string $locale): string
    {
        $names = [
            'chicago' => ['ru' => 'Чикаго', 'uk' => 'Чикаго'],
            'new york' => ['ru' => 'Нью-Йорк', 'uk' => 'Нью-Йорк'],
            'miami' => ['ru' => 'Майами', 'uk' => 'Маямі'],
            'los angeles' => ['ru' => 'Лос-Анджелес', 'uk' => 'Лос-Анджелес'],
        ];

        $lower = mb_strtolower($city);
        foreach ($names as $needle => $translations) {
            if (str_contains($lower, $needle)) {
                return $translations[$locale] ?? ucwords($needle);
            }
        }

        return $city;
    }

    /**
     * @return array{confirmed:string,pending:string,none:string}
     */
    private function copy(string $locale): array
    {
        $copy = [
            'en' => [
                'confirmed' => 'Confirmed show dates:',
                'pending' => 'The dates for :cities are at the confirmation stage and will be announced soon.',
                'none' => 'The dates of the next shows are at the confirmation stage and will be announced soon.',
            ],
            'ru' => [
                'confirmed' => 'Подтверждённые даты показов:',
                'pending' => 'Даты в :cities на стадии подтверждения и будут анонсированы в ближайшее время.',
                'none' => 'Даты ближайших показов на стадии подтверждения и будут анонсированы в ближайшее время.',
            ],
            'uk' => [
                'confirmed' => 'Підтверджені дати показів:',
                'pending' => 'Дати в :cities на стадії підтвердження і будуть анонсовані найближчим часом.',
                'none' => 'Дати найближчих показів на стадії підтвердження і будуть анонсовані найближчим часом.',
            ],
            'ro' => [
                'confirmed' => 'Date confirmate ale prezentărilor:',
                'pending' => 'Datele pentru :cities sunt în etapa de confirmare și vor fi anunțate în curând.',
                'none' => 'Datele următoarelor prezentări sunt în etapa de confirmare și vor fi anunțate în curând.',
            ],
            'es' => [
                'confirmed' => 'Fechas confirmadas de los desfiles:',
                'pending' => 'Las fechas de :cities están en fase de confirmación y se anunciarán muy pronto.',
                'none' => 'Las fechas de los próximos desfiles están en fase de confirmación y se anunciarán muy pronto.',
            ],
            'de' => [
                'confirmed' => 'Bestätigte Show-Termine:',
                'pending' => 'Die Termine für :cities befinden sich in der Bestätigungsphase und werden in Kürze bekannt gegeben.',
                'none' => 'Die Termine der nächsten Shows befinden sich in der Bestätigungsphase und werden in Kürze bekannt gegeben.',
            ],
        ];

        return $copy[$locale] ?? $copy['en'];
    }

    /**
     * @return list<array{y:?int,m:int,d:?int}>
     */
    private function dateClaims(string $text): array
    {
        $lower = mb_strtolower($text);
        $claims = [];

        if (preg_match_all('/(\d{4})-(\d{1,2})-(\d{1,2})/u', $lower, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                $claims[] = ['y' => (int) $match[1], 'm' => (int) $match[2], 'd' => (int) $match[3]];
            }
        }

        // 06.12.2026 or 12/06/2026 — the order is ambiguous, so both readings count as one claim each.
        if (preg_match_all('/\b(\d{1,2})[.\/](\d{1,2})[.\/](\d{4})\b/u', $lower, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                $claims[] = ['y' => (int) $match[3], 'm' => (int) $match[2], 'd' => (int) $match[1], 'swap' => true];
            }
        }

        // 26 апреля 2026 / 26 April 2026
        if (preg_match_all('/\b(\d{1,2})(?:-?го)?\s+([\p{L}]{3,})(?:\s+(\d{4}))?/u', $lower, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                $month = self::MONTHS[$match[2]] ?? null;
                if ($month !== null) {
                    $claims[] = ['y' => isset($match[3]) && $match[3] !== '' ? (int) $match[3] : null, 'm' => $month, 'd' => (int) $match[1]];
                }
            }
        }

        // April 26, 2027
        if (preg_match_all('/\b([\p{L}]{3,})\s+(\d{1,2})(?:,)?(?:\s+(\d{4}))?\b/u', $lower, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                $month = self::MONTHS[$match[1]] ?? null;
                if ($month !== null && (int) $match[2] >= 1 && (int) $match[2] <= 31) {
                    $claims[] = ['y' => isset($match[3]) && $match[3] !== '' ? (int) $match[3] : null, 'm' => $month, 'd' => (int) $match[2]];
                }
            }
        }

        // "в декабре 2026" / "December 2026" — a month claim without a day.
        if (preg_match_all('/\b([\p{L}]{3,})\s+(\d{4})\b/u', $lower, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                $month = self::MONTHS[$match[1]] ?? null;
                if ($month !== null) {
                    $claims[] = ['y' => (int) $match[2], 'm' => $month, 'd' => null];
                }
            }
        }

        return array_values($claims);
    }

    /**
     * @param  array{y:?int,m:int,d:?int,swap?:bool}  $claim
     * @param  list<string>  $allowed
     */
    private function isAllowed(array $claim, array $allowed): bool
    {
        foreach ($allowed as $date) {
            [$year, $month, $day] = array_map('intval', explode('-', $date));

            if ($claim['y'] !== null && $claim['y'] !== $year) {
                continue;
            }
            if ($claim['d'] === null) {
                if ($claim['m'] === $month) {
                    return true;
                }

                continue;
            }
            if ($claim['m'] === $month && $claim['d'] === $day) {
                return true;
            }
            // Ambiguous numeric dates may be day/month swapped.
            if (($claim['swap'] ?? false) && $claim['d'] === $month && $claim['m'] === $day) {
                return true;
            }
        }

        return false;
    }
}
