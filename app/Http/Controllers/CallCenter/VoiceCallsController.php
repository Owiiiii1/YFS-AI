<?php

namespace App\Http\Controllers\CallCenter;

use App\Http\Controllers\Controller;
use App\Models\VoiceCall;
use App\Models\VoiceContact;
use App\Services\Voice\Calls\VoiceTranscriptNormalizer;
use App\Services\Voice\Phone\PhoneNumberNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class VoiceCallsController extends Controller
{
    public function __construct(
        private readonly VoiceTranscriptNormalizer $transcripts,
        private readonly PhoneNumberNormalizer $phones,
    ) {}

    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('q', ''));
        $phoneFilter = trim((string) $request->query('phone', ''));
        $normalizedPhone = $phoneFilter !== '' ? $this->phones->normalize($phoneFilter) : '';

        $paginator = VoiceCall::query()
            ->with('contact')
            ->when($normalizedPhone !== '', fn (Builder $query) => $this->constrainByPhone($query, $normalizedPhone, $phoneFilter))
            ->when($normalizedPhone === '' && $search !== '', fn (Builder $query) => $this->constrainBySearch($query, $search))
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $selected = null;
        if ($request->filled('call')) {
            $call = VoiceCall::query()->with('contact')->find($request->integer('call'));
            $selected = $call !== null ? $this->toDetail($call) : null;
        }

        return Inertia::render('CallCenter/Index', [
            'calls' => $paginator->through(fn (VoiceCall $call): array => $this->toListRow($call)),
            'selectedCall' => $selected,
            'filters' => [
                'q' => $normalizedPhone === '' ? $search : '',
                'phone' => $normalizedPhone !== '' ? $normalizedPhone : '',
                'label' => $this->filterLabel($normalizedPhone, $search),
            ],
        ]);
    }

    public function contacts(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('q', ''));
        if ($search === '') {
            return response()->json(['contacts' => []]);
        }

        $like = $this->likeValue($search);
        $digits = preg_replace('/\D+/', '', $search) ?? '';

        $contacts = VoiceContact::query()
            ->where(function (Builder $query) use ($like, $digits): void {
                $query->where('name', 'like', $like)
                    ->orWhere('phone_display', 'like', $like)
                    ->orWhere('phone_normalized', 'like', $like);
                if ($digits !== '') {
                    $query->orWhere('phone_normalized', 'like', $this->likeValue($digits))
                        ->orWhere('phone_display', 'like', $this->likeValue($digits));
                }
            })
            ->orderByDesc('last_called_at')
            ->orderByDesc('id')
            ->limit(8)
            ->get()
            ->map(fn (VoiceContact $contact): array => $this->toSuggestion($contact))
            ->values()
            ->all();

        return response()->json(['contacts' => $contacts]);
    }

    private function constrainByPhone(Builder $query, string $normalizedPhone, string $rawPhone): Builder
    {
        return $query->where(function (Builder $builder) use ($normalizedPhone, $rawPhone): void {
            $builder->whereHas(
                'contact',
                fn (Builder $contact) => $contact->where('phone_normalized', $normalizedPhone),
            )->orWhere('phone', $normalizedPhone);

            if ($rawPhone !== '' && $rawPhone !== $normalizedPhone) {
                $builder->orWhere('phone', $rawPhone);
            }
        });
    }

    private function constrainBySearch(Builder $query, string $search): Builder
    {
        $like = $this->likeValue($search);
        $digits = preg_replace('/\D+/', '', $search) ?? '';

        return $query->where(function (Builder $builder) use ($like, $digits): void {
            $builder->where('phone', 'like', $like)
                ->orWhereHas('contact', function (Builder $contact) use ($like, $digits): void {
                    $contact->where('name', 'like', $like)
                        ->orWhere('phone_display', 'like', $like)
                        ->orWhere('phone_normalized', 'like', $like);
                    if ($digits !== '') {
                        $contact->orWhere('phone_normalized', 'like', $this->likeValue($digits))
                            ->orWhere('phone_display', 'like', $this->likeValue($digits));
                    }
                });
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function toListRow(VoiceCall $call): array
    {
        $contact = $call->contact;
        $turns = is_array($call->transcript) ? $call->transcript : [];

        return [
            'id' => $call->id,
            'started_at' => optional($call->started_at ?? $call->created_at)?->toDateTimeString(),
            'contact_name' => filled($contact?->name) ? (string) $contact->name : null,
            'phone' => $call->phone ?: ($contact?->phone_display ?: $contact?->phone_normalized),
            'phone_normalized' => $contact?->phone_normalized ?: $this->phones->normalize((string) $call->phone),
            'language' => $call->language,
            'duration_seconds' => $call->duration_seconds,
            'status' => $call->status,
            'brief' => filled($call->summary)
                ? (string) $call->summary
                : $this->transcripts->preview($turns),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function toDetail(VoiceCall $call): array
    {
        $contact = $call->contact;
        $turns = is_array($call->transcript) ? $call->transcript : [];
        $recordingUrl = $this->playableUrl($call->recording_url);
        $phoneNormalized = $contact?->phone_normalized ?: $this->phones->normalize((string) $call->phone);

        return [
            ...$this->toListRow($call),
            'contact' => [
                'name' => filled($contact?->name) ? (string) $contact->name : null,
                'phone' => $contact?->phone_display ?: $contact?->phone_normalized,
                'phone_normalized' => $phoneNormalized !== '' ? $phoneNormalized : null,
                'preferred_language' => $contact?->preferred_language,
                'calls_count' => (int) ($contact?->calls_count ?? 0),
                'first_called_at' => optional($contact?->first_called_at)?->toDateTimeString(),
                'last_called_at' => optional($contact?->last_called_at)?->toDateTimeString(),
                // Future: yfs_customer, package, show, bitrix_contact — not wired.
            ],
            'call_language' => $call->language,
            'transcript' => array_map(fn (array $turn): array => [
                'speaker' => ($turn['speaker'] ?? '') === 'assistant' ? 'assistant' : 'client',
                'message' => $this->transcripts->sanitizeMessage((string) ($turn['message'] ?? '')),
            ], $turns),
            'summary' => $call->summary,
            'recording_url' => $recordingUrl,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function toSuggestion(VoiceContact $contact): array
    {
        return [
            'id' => $contact->id,
            'name' => filled($contact->name) ? (string) $contact->name : null,
            'phone' => $contact->phone_display ?: $contact->phone_normalized,
            'phone_normalized' => $contact->phone_normalized,
            'calls_count' => (int) $contact->calls_count,
        ];
    }

    private function filterLabel(string $normalizedPhone, string $search): string
    {
        if ($normalizedPhone !== '') {
            $contact = VoiceContact::query()->where('phone_normalized', $normalizedPhone)->first();
            if ($contact === null) {
                return $normalizedPhone;
            }

            $phone = $contact->phone_display ?: $contact->phone_normalized;

            return filled($contact->name) ? $contact->name.' · '.$phone : $phone;
        }

        return $search;
    }

    private function likeValue(string $value): string
    {
        return '%'.addcslashes($value, '%_\\').'%';
    }

    private function playableUrl(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        return preg_match('#^https?://#i', $url) === 1 ? $url : null;
    }
}
