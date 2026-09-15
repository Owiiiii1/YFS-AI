<?php

namespace App\Services\Voice\Tools;

use App\Services\Jfs\JfsReadService;
use Illuminate\Support\Facades\Log;

final class GetShowBrandsVoiceTool implements VoiceToolInterface, VoiceToolMetadataProvider
{
    public const NAME = 'get_show_brands';

    public function __construct(
        private readonly JfsReadService $jfs,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Returns the public brand/designer lineup for Young Fashion Show events from YFS Core. '
            .'Call when the caller asks which brands participate, which designers are on a show, the lineup for a city or show, or whether brands have been published. '
            .'Prefer this tool over memory or static policy for public lineup facts. '
            .'This is a public show lineup only. Do not use it to answer which brand is assigned to a specific child, family, or participant, fitting assignments, or number of looks. '
            .'If brands is empty or lineup_published is false, the lineup is not published yet — do not invent brand names. '
            .'Optional show_name and city filters narrow the list.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'show_name' => [
                    'type' => 'string',
                    'description' => 'Optional show name filter. Case-insensitive substring match. Omit to return all public show lineups.',
                ],
                'city' => [
                    'type' => 'string',
                    'description' => 'Optional city filter. Case-insensitive substring match. Omit to return all public show lineups.',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function execute(array $arguments): array
    {
        $lineups = $this->jfs->publicBrandLineups();
        if ($this->jfs->lastReadFailed()) {
            Log::warning('voice.yfs_core_tool.source_unavailable', [
                'tool' => self::NAME,
            ]);

            return YfsCoreLiveToolSupport::unavailable(self::NAME);
        }

        $filtered = YfsCoreLiveToolSupport::filterByShowAndCity(
            $lineups,
            YfsCoreLiveToolSupport::stringArgument($arguments, 'show_name'),
            YfsCoreLiveToolSupport::stringArgument($arguments, 'city'),
        );

        $results = array_map(fn (array $lineup): array => $this->mapLineup($lineup), $filtered);

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
     * @param  array<string, mixed>  $lineup
     * @return array{
     *     name: string,
     *     city: string,
     *     starts_at: ?string,
     *     date_announced: bool,
     *     is_past: bool,
     *     brand_count: int,
     *     brands: list<string>,
     *     lineup_published: bool
     * }
     */
    private function mapLineup(array $lineup): array
    {
        $announced = (bool) ($lineup['date_announced'] ?? false);
        $brands = [];
        foreach ($lineup['brands'] ?? [] as $brand) {
            if (! is_string($brand)) {
                continue;
            }
            $name = trim($brand);
            if ($name === '') {
                continue;
            }
            $brands[] = $name;
        }

        return [
            'name' => (string) ($lineup['name'] ?? ''),
            'city' => (string) ($lineup['city'] ?? ''),
            'starts_at' => $announced ? $this->nullableString($lineup['starts_at'] ?? null) : null,
            'date_announced' => $announced,
            'is_past' => (bool) ($lineup['is_past'] ?? false),
            'brand_count' => count($brands),
            'brands' => $brands,
            'lineup_published' => $brands !== [],
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
