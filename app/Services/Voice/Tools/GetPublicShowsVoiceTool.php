<?php

namespace App\Services\Voice\Tools;

use App\Services\Jfs\JfsReadService;
use Illuminate\Support\Facades\Log;

final class GetPublicShowsVoiceTool implements VoiceToolInterface, VoiceToolMetadataProvider
{
    public const NAME = 'get_public_shows';

    public function __construct(
        private readonly JfsReadService $jfs,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Returns current public Young Fashion Show events from YFS Core: name, city, venue/location, dates only when announced, and a short description. '
            .'Call when the caller asks which shows exist, when a show is, where it is, which city, venue/location, current or upcoming shows, or details about a specific show. '
            .'Prefer this tool over memory or static policy for live show facts. '
            .'Optional show_name and city filters narrow the list. '
            .'If date_announced is false or starts_at/ends_at is null, the date is not announced — do not guess it. '
            .'This is public show information only. It is not a personal schedule, rehearsal timetable, ticket, or package lookup.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'show_name' => [
                    'type' => 'string',
                    'description' => 'Optional show name filter. Case-insensitive substring match. Omit to return all public shows.',
                ],
                'city' => [
                    'type' => 'string',
                    'description' => 'Optional city filter. Case-insensitive substring match. Omit to return all public shows.',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function execute(array $arguments): array
    {
        $events = $this->jfs->publicEvents();
        if ($this->jfs->lastReadFailed()) {
            Log::warning('voice.yfs_core_tool.source_unavailable', [
                'tool' => self::NAME,
            ]);

            return YfsCoreLiveToolSupport::unavailable(self::NAME);
        }

        $filtered = YfsCoreLiveToolSupport::filterByShowAndCity(
            $events,
            YfsCoreLiveToolSupport::stringArgument($arguments, 'show_name'),
            YfsCoreLiveToolSupport::stringArgument($arguments, 'city'),
        );

        $results = array_map(fn (array $event): array => $this->mapEvent($event), $filtered);

        return YfsCoreLiveToolSupport::payload(
            self::NAME,
            true,
            $results,
            $results === [] ? YfsCoreLiveToolSupport::MESSAGE_NO_MATCHING_SHOWS : null,
        );
    }

    public function metadata(): VoiceToolMetadata
    {
        return new VoiceToolMetadata(
            category: 'lookup',
            estimatedLatency: 'short',
            fillerEnabled: true,
            readOnly: true,
            source: YfsCoreLiveToolSupport::SOURCE,
        );
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array{
     *     name: string,
     *     city: string,
     *     location: string,
     *     starts_at: ?string,
     *     ends_at: ?string,
     *     date_announced: bool,
     *     is_past: bool,
     *     description: ?string
     * }
     */
    private function mapEvent(array $event): array
    {
        $announced = (bool) ($event['date_announced'] ?? false);

        return [
            'name' => (string) ($event['name'] ?? ''),
            'city' => (string) ($event['city'] ?? ''),
            'location' => (string) ($event['location'] ?? ''),
            'starts_at' => $announced ? $this->nullableString($event['starts_at'] ?? null) : null,
            'ends_at' => $announced ? $this->nullableString($event['ends_at'] ?? null) : null,
            'date_announced' => $announced,
            'is_past' => (bool) ($event['is_past'] ?? false),
            'description' => $this->nullableString($event['description'] ?? null),
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $text = trim($value);

        return $text === '' ? null : $text;
    }
}
