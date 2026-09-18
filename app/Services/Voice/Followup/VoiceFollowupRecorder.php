<?php

namespace App\Services\Voice\Followup;

use App\Models\VoiceCall;
use App\Models\VoiceContact;
use App\Models\VoiceFollowup;
use App\Services\Voice\Contacts\VoiceContactDirectory;
use App\Services\Voice\Identity\VoiceContactSessionResolver;
use App\Services\Voice\Phone\PhoneNumberNormalizer;
use Illuminate\Support\Facades\DB;

final class VoiceFollowupRecorder
{
    public const STATUS_CREATED = 'created';

    public const STATUS_ALREADY_CREATED = 'already_created';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_FAILED = 'failed';

    public function __construct(
        private readonly VoiceContactSessionResolver $sessions,
        private readonly VoiceContactDirectory $directory,
        private readonly PhoneNumberNormalizer $phones,
        private readonly VoiceFollowupNotifier $notifier,
    ) {}

    /**
     * @param  array{
     *     department?: string,
     *     reason?: string,
     *     callback_requested?: bool,
     *     callback_phone?: ?string,
     *     preferred_callback_time?: ?string,
     *     customer_name?: ?string,
     *     child_name?: ?string,
     *     show_city?: ?string,
     *     summary?: ?string,
     *     system__caller_id?: ?string,
     *     system__conversation_id?: ?string
     * }  $input
     * @return array{ok: bool, status: string, department?: string}
     */
    public function recordFromLiveTool(array $input): array
    {
        $conversationId = trim((string) ($input['system__conversation_id'] ?? ''));
        $callerId = trim((string) ($input['system__caller_id'] ?? ''));
        $department = $this->normalizeDepartment($input['department'] ?? null);
        $reason = $this->clip((string) ($input['reason'] ?? ''), 500);

        if ($conversationId === '' || $department === null || $reason === '') {
            return $this->failed();
        }

        $contact = $this->sessions->findTrusted(
            $callerId !== '' ? $callerId : null,
            $conversationId,
        );
        if ($contact === null) {
            return $this->failed($department);
        }

        $this->directory->rememberConversationId($contact, $conversationId);

        $callbackRequested = (bool) ($input['callback_requested'] ?? false);
        $dictatedPhone = $this->clip((string) ($input['callback_phone'] ?? ''), 32);
        $callbackPhone = $this->resolveCallbackPhone(
            $callbackRequested,
            $dictatedPhone,
            $callerId,
            $contact,
        );

        return $this->persistAndNotify(
            conversationId: $conversationId,
            contact: $contact,
            department: $department,
            reason: $reason,
            callbackRequested: $callbackRequested,
            callbackPhone: $callbackPhone,
            preferredCallbackTime: $this->clip((string) ($input['preferred_callback_time'] ?? ''), 120),
            customerName: $this->clip((string) ($input['customer_name'] ?? ''), 120),
            childName: $this->clip((string) ($input['child_name'] ?? ''), 120),
            showCity: $this->clip((string) ($input['show_city'] ?? ''), 120),
            summary: $this->clip((string) ($input['summary'] ?? ''), 1000),
            createdBy: VoiceFollowup::CREATED_BY_LIVE_TOOL,
            recoveredTelegram: false,
            voiceCallId: $this->existingCallId($conversationId),
        );
    }

    /**
     * @param  array{
     *     department: string,
     *     reason: string,
     *     callback_requested?: bool,
     *     callback_phone?: ?string,
     *     preferred_callback_time?: ?string,
     *     customer_name?: ?string,
     *     child_name?: ?string,
     *     show_city?: ?string,
     *     summary?: ?string
     * }  $fields
     */
    public function recordFromSafetyNet(VoiceCall $call, array $fields): ?VoiceFollowup
    {
        $conversationId = trim((string) $call->elevenlabs_conversation_id);
        $department = $this->normalizeDepartment($fields['department'] ?? VoiceFollowup::DEPARTMENT_SALES)
            ?? VoiceFollowup::DEPARTMENT_SALES;
        $reason = $this->clip((string) ($fields['reason'] ?? ''), 500);
        if ($conversationId === '' || $reason === '' || $call->voice_contact_id === null) {
            return null;
        }

        $contact = $call->contact ?? VoiceContact::query()->find($call->voice_contact_id);
        if ($contact === null) {
            return null;
        }

        $this->persistAndNotify(
            conversationId: $conversationId,
            contact: $contact,
            department: $department,
            reason: $reason,
            callbackRequested: (bool) ($fields['callback_requested'] ?? false),
            callbackPhone: $this->callablePhone((string) ($fields['callback_phone'] ?? ''))
                ?? $this->trustedCallerPhone((string) $call->phone, $contact),
            preferredCallbackTime: $this->clip((string) ($fields['preferred_callback_time'] ?? ''), 120),
            customerName: $this->clip((string) ($fields['customer_name'] ?? ''), 120),
            childName: $this->clip((string) ($fields['child_name'] ?? ''), 120),
            showCity: $this->clip((string) ($fields['show_city'] ?? ''), 120),
            summary: $this->clip((string) ($fields['summary'] ?? ''), 1000),
            createdBy: VoiceFollowup::CREATED_BY_SAFETY_NET,
            recoveredTelegram: true,
            voiceCallId: $call->id,
        );

        return VoiceFollowup::query()->where('elevenlabs_conversation_id', $conversationId)->first();
    }

    public function existingForConversation(string $conversationId): ?VoiceFollowup
    {
        $conversationId = trim($conversationId);
        if ($conversationId === '') {
            return null;
        }

        return VoiceFollowup::query()->where('elevenlabs_conversation_id', $conversationId)->first();
    }

    public function attachCallIfMissing(VoiceFollowup $followup, VoiceCall $call): void
    {
        if ($followup->voice_call_id !== null) {
            return;
        }

        $followup->forceFill(['voice_call_id' => $call->id])->save();
    }

    /**
     * @return array{ok: bool, status: string, department: string}
     */
    private function persistAndNotify(
        string $conversationId,
        VoiceContact $contact,
        string $department,
        string $reason,
        bool $callbackRequested,
        ?string $callbackPhone,
        ?string $preferredCallbackTime,
        ?string $customerName,
        ?string $childName,
        ?string $showCity,
        ?string $summary,
        string $createdBy,
        bool $recoveredTelegram,
        ?int $voiceCallId,
    ): array {
        $created = false;

        $followup = DB::transaction(function () use (
            $conversationId,
            $contact,
            $department,
            $reason,
            $callbackRequested,
            $callbackPhone,
            $preferredCallbackTime,
            $customerName,
            $childName,
            $showCity,
            $summary,
            $createdBy,
            $voiceCallId,
            &$created,
        ): VoiceFollowup {
            $existing = VoiceFollowup::query()
                ->where('elevenlabs_conversation_id', $conversationId)
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                if ($existing->voice_call_id === null && $voiceCallId !== null) {
                    $existing->forceFill(['voice_call_id' => $voiceCallId])->save();
                }

                return $existing;
            }

            $created = true;

            return VoiceFollowup::query()->create([
                'voice_call_id' => $voiceCallId,
                'voice_contact_id' => $contact->id,
                'elevenlabs_conversation_id' => $conversationId,
                'department' => $department,
                'reason' => $reason,
                'status' => VoiceFollowup::STATUS_OPEN,
                'callback_requested' => $callbackRequested,
                'callback_phone' => $callbackPhone,
                'preferred_callback_time' => $preferredCallbackTime !== '' ? $preferredCallbackTime : null,
                'customer_name' => $customerName !== '' ? $customerName : null,
                'child_name' => $childName !== '' ? $childName : null,
                'show_city' => $showCity !== '' ? $showCity : null,
                'summary' => $summary !== '' ? $summary : null,
                'source' => VoiceFollowup::SOURCE_VOICE,
                'telegram_sent_at' => null,
                'metadata' => ['created_by' => $createdBy],
            ]);
        });

        $delivered = $this->notifier->sendIfNeeded($followup, $recoveredTelegram && $created);
        $department = (string) $followup->department;

        if ($delivered) {
            return [
                'ok' => true,
                'status' => $created ? self::STATUS_CREATED : self::STATUS_ALREADY_CREATED,
                'department' => $department,
            ];
        }

        return [
            'ok' => false,
            'status' => self::STATUS_QUEUED,
            'department' => $department,
        ];
    }

    private function resolveCallbackPhone(
        bool $callbackRequested,
        string $dictatedPhone,
        string $callerId,
        VoiceContact $contact,
    ): ?string {
        if ($dictatedPhone !== '') {
            return $this->callablePhone($dictatedPhone);
        }

        if (! $callbackRequested) {
            return null;
        }

        return $this->trustedCallerPhone($callerId, $contact);
    }

    private function trustedCallerPhone(string $callerId, VoiceContact $contact): ?string
    {
        return $this->callablePhone($callerId)
            ?? $this->callablePhone((string) $contact->phone_normalized)
            ?? $this->callablePhone((string) $contact->phone_display);
    }

    private function callablePhone(string $raw): ?string
    {
        $normalized = $this->phones->normalize($raw);
        if ($normalized === '' || ! preg_match('/^\+\d{8,15}$/', $normalized)) {
            return null;
        }

        return $normalized;
    }

    private function normalizeDepartment(mixed $value): ?string
    {
        $department = strtolower(trim((string) $value));

        return in_array($department, [VoiceFollowup::DEPARTMENT_SALES, VoiceFollowup::DEPARTMENT_SUPPORT], true)
            ? $department
            : null;
    }

    private function existingCallId(string $conversationId): ?int
    {
        $id = VoiceCall::query()
            ->where('elevenlabs_conversation_id', $conversationId)
            ->value('id');

        return is_numeric($id) ? (int) $id : null;
    }

    private function clip(string $value, int $max): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        return mb_strlen($value) <= $max ? $value : mb_substr($value, 0, $max);
    }

    /**
     * @return array{ok: false, status: string, department?: string}
     */
    private function failed(?string $department = null): array
    {
        $payload = [
            'ok' => false,
            'status' => self::STATUS_FAILED,
        ];
        if ($department !== null) {
            $payload['department'] = $department;
        }

        return $payload;
    }
}
