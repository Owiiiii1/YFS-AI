<?php

namespace App\Http\Controllers\CallCenter;

use App\Http\Controllers\Controller;
use App\Models\VoiceCall;
use App\Services\Voice\Calls\VoiceTranscriptNormalizer;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class VoiceCallsController extends Controller
{
    public function __construct(
        private readonly VoiceTranscriptNormalizer $transcripts,
    ) {}

    public function index(Request $request): Response
    {
        $paginator = VoiceCall::query()
            ->with('contact')
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
        ]);
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

        return [
            ...$this->toListRow($call),
            'contact' => [
                'name' => filled($contact?->name) ? (string) $contact->name : null,
                'phone' => $contact?->phone_display ?: $contact?->phone_normalized,
                'preferred_language' => $contact?->preferred_language,
                'calls_count' => (int) ($contact?->calls_count ?? 0),
                'first_called_at' => optional($contact?->first_called_at)?->toDateTimeString(),
                'last_called_at' => optional($contact?->last_called_at)?->toDateTimeString(),
                // Future: yfs_customer, package, show, bitrix_contact — not wired.
            ],
            'call_language' => $call->language,
            'transcript' => array_map(static fn (array $turn): array => [
                'speaker' => ($turn['speaker'] ?? '') === 'assistant' ? 'assistant' : 'client',
                'message' => (string) ($turn['message'] ?? ''),
            ], $turns),
            'summary' => $call->summary,
            'recording_url' => $recordingUrl,
        ];
    }

    private function playableUrl(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        return preg_match('#^https?://#i', $url) === 1 ? $url : null;
    }
}
