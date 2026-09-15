<?php

namespace App\Services\Voice\Calls;

use App\Models\VoiceCall;
use App\Models\VoiceContact;
use App\Services\Voice\Contacts\VoiceContactDirectory;
use App\Support\VoiceSupportedLanguage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ElevenLabsPostCallPersister
{
    public function __construct(
        private readonly VoiceContactDirectory $directory,
        private readonly VoiceTranscriptNormalizer $transcripts,
    ) {}

    /**
     * Persist a post_call_transcription payload. Idempotent on elevenlabs_conversation_id.
     * Extra / unknown fields are ignored. Optional fields may be absent.
     *
     * @param  array<string, mixed>  $payload
     */
    public function persist(array $payload): ?VoiceCall
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $conversationId = $this->nullableString($data['conversation_id'] ?? null);
        if ($conversationId === null) {
            return null;
        }

        $metadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
        $phoneCall = is_array($metadata['phone_call'] ?? null) ? $metadata['phone_call'] : [];
        $analysis = is_array($data['analysis'] ?? null) ? $data['analysis'] : [];
        $initiation = is_array($data['conversation_initiation_client_data'] ?? null)
            ? $data['conversation_initiation_client_data']
            : [];

        $phone = $this->nullableString($phoneCall['external_number'] ?? null);
        $callSid = $this->nullableString($phoneCall['call_sid'] ?? null);
        $status = $this->nullableString($data['status'] ?? null) ?? 'done';
        $duration = isset($metadata['call_duration_secs']) && is_numeric($metadata['call_duration_secs'])
            ? max(0, (int) $metadata['call_duration_secs'])
            : null;
        $startedAt = $this->timestampFromUnix($metadata['start_time_unix_secs'] ?? null);
        $endedAt = $startedAt !== null && $duration !== null
            ? $startedAt->copy()->addSeconds($duration)
            : null;

        $detectedLanguage = VoiceSupportedLanguage::tryNormalize(
            $this->nullableString($metadata['main_language'] ?? null)
            ?? $this->nullableString(data_get($initiation, 'conversation_config_override.agent.language')),
        );

        $summary = $this->nullableString($analysis['transcript_summary'] ?? null);
        $transcript = $this->transcripts->normalize($data['transcript'] ?? null);
        $recordingUrl = $this->extractRecordingUrl($data, $metadata);
        $safeMetadata = $this->safeMetadata($payload, $data, $metadata, $phoneCall, $analysis);

        return DB::transaction(function () use (
            $conversationId,
            $phone,
            $callSid,
            $status,
            $duration,
            $startedAt,
            $endedAt,
            $detectedLanguage,
            $summary,
            $transcript,
            $recordingUrl,
            $safeMetadata,
        ): VoiceCall {
            $contact = $this->resolveContact($conversationId, $phone);
            $existing = VoiceCall::query()
                ->where('elevenlabs_conversation_id', $conversationId)
                ->lockForUpdate()
                ->first();

            $attributes = [
                'voice_contact_id' => $contact->id,
                'elevenlabs_conversation_id' => $conversationId,
                'twilio_call_sid' => $callSid ?? $existing?->twilio_call_sid,
                'phone' => $phone ?? $existing?->phone ?? $contact->phone_display ?? $contact->phone_normalized,
                'language' => $detectedLanguage ?? $existing?->language,
                'started_at' => $startedAt ?? $existing?->started_at,
                'ended_at' => $endedAt ?? $existing?->ended_at,
                'duration_seconds' => $duration ?? $existing?->duration_seconds,
                'status' => $status,
                'transcript' => $transcript !== [] ? $transcript : ($existing?->transcript ?? []),
                'summary' => $summary ?? $existing?->summary,
                'recording_url' => $recordingUrl ?? $existing?->recording_url,
                'metadata' => $safeMetadata !== [] ? $safeMetadata : ($existing?->metadata ?? []),
            ];

            if ($existing !== null) {
                $existing->fill($attributes)->save();
                $this->touchContact($contact, $startedAt ?? $endedAt, $detectedLanguage, incrementCalls: false);

                return $existing->fresh('contact');
            }

            $call = VoiceCall::query()->create($attributes);
            $this->touchContact($contact, $startedAt ?? $endedAt, $detectedLanguage, incrementCalls: true);

            return $call->fresh('contact');
        });
    }

    private function resolveContact(string $conversationId, ?string $phone): VoiceContact
    {
        $contact = $this->directory->findOrCreateFromCallerId($phone);
        if ($contact !== null) {
            return $contact;
        }

        $fallback = $this->directory->findOrCreateFromCallerId('cid:'.$conversationId);
        if ($fallback === null) {
            throw new \RuntimeException('Unable to create a voice contact for the post-call payload.');
        }

        return $fallback;
    }

    private function touchContact(
        VoiceContact $contact,
        ?Carbon $calledAt,
        ?string $language,
        bool $incrementCalls,
    ): void {
        $updates = [];
        $at = $calledAt ?? now();

        if ($contact->first_called_at === null) {
            $updates['first_called_at'] = $at;
        }
        if ($contact->last_called_at === null || $at->greaterThan($contact->last_called_at)) {
            $updates['last_called_at'] = $at;
        }
        if ($language !== null) {
            $updates['preferred_language'] = $language;
        }

        if ($updates !== []) {
            $contact->forceFill($updates)->save();
        }

        if ($incrementCalls) {
            $contact->increment('calls_count');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $metadata
     */
    private function extractRecordingUrl(array $data, array $metadata): ?string
    {
        $candidates = [
            $data['recording_url'] ?? null,
            $data['audio_url'] ?? null,
            $metadata['recording_url'] ?? null,
            $metadata['audio_url'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }
            $url = trim($candidate);
            if (preg_match('#^https?://#i', $url) === 1) {
                return $url;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $phoneCall
     * @param  array<string, mixed>  $analysis
     * @return array<string, mixed>
     */
    private function safeMetadata(
        array $payload,
        array $data,
        array $metadata,
        array $phoneCall,
        array $analysis,
    ): array {
        $safe = [
            'type' => $this->nullableString($payload['type'] ?? null),
            'event_timestamp' => isset($payload['event_timestamp']) && is_numeric($payload['event_timestamp'])
                ? (int) $payload['event_timestamp']
                : null,
            'agent_id' => $this->nullableString($data['agent_id'] ?? null),
            'status' => $this->nullableString($data['status'] ?? null),
            'termination_reason' => $this->nullableString($metadata['termination_reason'] ?? null),
            'main_language' => $this->nullableString($metadata['main_language'] ?? null),
            'has_audio' => array_key_exists('has_audio', $data) ? (bool) $data['has_audio'] : null,
            'call_successful' => $this->nullableString($analysis['call_successful'] ?? null),
            'phone_call' => array_filter([
                'type' => $this->nullableString($phoneCall['type'] ?? null),
                'direction' => $this->nullableString($phoneCall['direction'] ?? null),
                'agent_number' => $this->nullableString($phoneCall['agent_number'] ?? null),
            ], static fn ($value) => $value !== null),
        ];

        return array_filter($safe, static fn ($value) => $value !== null && $value !== []);
    }

    private function timestampFromUnix(mixed $value): ?Carbon
    {
        if (! is_numeric($value)) {
            return null;
        }

        $unix = (int) $value;
        if ($unix <= 0) {
            return null;
        }

        return Carbon::createFromTimestamp($unix);
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
