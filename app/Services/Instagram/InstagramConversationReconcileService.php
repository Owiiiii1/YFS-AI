<?php

namespace App\Services\Instagram;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\InstagramAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class InstagramConversationReconcileService
{
    private const LOOKBACK_HOURS = 120;

    private const AUTO_REPLY_MAX_AGE_HOURS = 24;

    private const CONVERSATIONS_PAGE_LIMIT = 50;

    private const MESSAGES_PER_THREAD = 40;

    private const MAX_CONVERSATION_PAGES = 3;

    public function __construct(
        private readonly ProcessIncomingInstagramMessageService $incomingService,
        private readonly ProcessOutgoingInstagramEchoService $echoService,
        private readonly InstagramMediaResolver $mediaResolver,
        private readonly InstagramGraphClient $graph,
    ) {}

    /**
     * @return array{scanned: int, imported_inbound: int, imported_outbound: int, replied: int, errors: int}
     */
    public function reconcile(?int $lookbackHours = null): array
    {
        $idle = [
            'scanned' => 0,
            'imported_inbound' => 0,
            'imported_outbound' => 0,
            'replied' => 0,
            'errors' => 0,
        ];

        $lock = Cache::lock('instagram-conversation-reconcile', 600);
        if (! $lock->get()) {
            Log::info('Instagram reconcile skipped: already running.');

            return $idle;
        }

        try {
            return $this->runReconcile($lookbackHours);
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{scanned: int, imported_inbound: int, imported_outbound: int, replied: int, errors: int}
     */
    private function runReconcile(?int $lookbackHours = null): array
    {
        $stats = [
            'scanned' => 0,
            'imported_inbound' => 0,
            'imported_outbound' => 0,
            'replied' => 0,
            'errors' => 0,
        ];

        $account = InstagramAccount::primary();
        if (! $account->isConnected()) {
            return $stats;
        }

        $token = (string) $account->access_token_encrypted;
        $hours = $lookbackHours !== null && $lookbackHours > 0 ? $lookbackHours : self::LOOKBACK_HOURS;
        $cutoff = now()->subHours($hours);

        try {
            $threads = $this->fetchRecentThreads($account, $token, $cutoff);
        } catch (Throwable $exception) {
            Log::error('Instagram reconcile failed to list conversations.', [
                'message' => $exception->getMessage(),
                'ig_user_id' => $account->instagram_user_id,
                'token_length' => strlen($token),
            ]);
            Cache::put('instagram-reconcile-fail-streak', ((int) Cache::get('instagram-reconcile-fail-streak', 0)) + 1, now()->addHours(6));
            $stats['errors']++;

            return $stats;
        }

        Cache::forget('instagram-reconcile-fail-streak');

        foreach ($threads as $thread) {
            $stats['scanned']++;

            try {
                $result = $this->reconcileThread($account, $token, $thread);
                $stats['imported_inbound'] += $result['imported_inbound'];
                $stats['imported_outbound'] += $result['imported_outbound'];
                $stats['replied'] += $result['replied'];
            } catch (Throwable $exception) {
                $stats['errors']++;
                Log::warning('Instagram reconcile thread failed.', [
                    'conversation_id' => $thread['id'] ?? null,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        try {
            Log::info('Instagram conversation reconcile finished.', $stats);
        } catch (Throwable) {
            // ignore
        }

        return $stats;
    }

    /**
     * Pull one Instagram thread and import any messages the webhook missed.
     *
     * @return array{imported_inbound: int, imported_outbound: int, replied: int}
     */
    public function importForParticipant(string $participantId, bool $allowAutoReply = false): array
    {
        $idle = [
            'imported_inbound' => 0,
            'imported_outbound' => 0,
            'replied' => 0,
        ];

        $participantId = trim($participantId);
        if ($participantId === '') {
            return $idle;
        }

        $cacheKey = 'ig-thread-catchup-'.$participantId;
        if (! Cache::add($cacheKey, 1, 20)) {
            return $idle;
        }

        $account = InstagramAccount::primary();
        if (! $account->isConnected()) {
            return $idle;
        }

        $token = (string) $account->access_token_encrypted;
        $actors = array_values(array_unique(array_filter([
            trim((string) $account->instagram_user_id),
            'me',
        ])));

        $query = [
            'platform' => 'instagram',
            'user_id' => $participantId,
            'fields' => 'id,updated_time,participants',
        ];

        $thread = null;
        $lastError = null;

        foreach ($actors as $actorId) {
            $response = $this->graph->get($token, $actorId.'/conversations', $query);
            if (! $response->successful()) {
                $lastError = 'HTTP '.$response->status().' '.$response->body();

                continue;
            }

            $row = $response->json('data.0');
            if (is_array($row)) {
                $thread = $row;

                break;
            }
        }

        if ($thread === null) {
            Cache::forget($cacheKey);
            Log::warning('Instagram thread catch-up failed.', [
                'participant_id' => $participantId,
                'message' => $lastError,
            ]);

            return $idle;
        }

        $username = null;
        foreach ((array) data_get($thread, 'participants.data', []) as $participant) {
            if (! is_array($participant) || $this->participantIsBusiness($participant, $account)) {
                continue;
            }
            $name = trim((string) ($participant['username'] ?? ''));
            $username = $name !== '' ? $name : null;

            break;
        }

        return $this->reconcileThread($account, $token, [
            'id' => (string) ($thread['id'] ?? ''),
            'participant_id' => $participantId,
            'participant_username' => $username,
            'updated_time' => (string) ($thread['updated_time'] ?? now()->toIso8601String()),
        ], $allowAutoReply);
    }

    /**
     * @return list<array{id: string, participant_id: string, participant_username: ?string, updated_time: string}>
     */
    private function fetchRecentThreads(InstagramAccount $account, string $token, Carbon $cutoff): array
    {
        $igUserId = trim((string) $account->instagram_user_id);
        $professionalId = trim((string) data_get($account->settings, 'instagram_profile.user_id', ''));
        $candidates = array_values(array_unique(array_filter([$igUserId, 'me', $professionalId])));

        $threads = [];
        $pages = 0;
        $url = null;
        $query = [
            'platform' => 'instagram',
            'fields' => 'participants,updated_time,id',
            'limit' => self::CONVERSATIONS_PAGE_LIMIT,
        ];
        $lastError = null;

        foreach ($candidates as $actorId) {
            $response = $this->graph->get($token, $actorId.'/conversations', $query);
            if ($response->successful()) {
                $url = $this->graph->url($actorId.'/conversations');
                break;
            }
            $lastError = 'Conversations list failed for '.$actorId.': HTTP '.$response->status().' '.$response->body();
        }

        if ($url === null) {
            throw new \RuntimeException($lastError ?? 'Conversations list failed.');
        }

        while ($url !== null && $pages < self::MAX_CONVERSATION_PAGES) {
            $pages++;
            $response = $pages === 1
                ? $this->graph->get($token, $url, $query)
                : $this->graph->get($token, $url);

            if (! $response->successful()) {
                throw new \RuntimeException(
                    'Conversations list failed: HTTP '.$response->status().' '.$response->body()
                );
            }

            $olderThanCutoff = false;

            foreach ((array) $response->json('data', []) as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $updated = Carbon::parse((string) ($row['updated_time'] ?? now()->toIso8601String()));
                if ($updated->lt($cutoff)) {
                    $olderThanCutoff = true;

                    continue;
                }

                $participant = $this->resolveCustomerParticipant($row, $account);
                if ($participant === null) {
                    continue;
                }

                $threads[] = [
                    'id' => (string) ($row['id'] ?? ''),
                    'participant_id' => $participant['id'],
                    'participant_username' => $participant['username'],
                    'updated_time' => $updated->toIso8601String(),
                ];
            }

            if ($olderThanCutoff) {
                break;
            }

            $next = $response->json('paging.next');
            $url = is_string($next) && $next !== '' ? $next : null;
        }

        return $threads;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{id: string, username: ?string}|null
     */
    private function resolveCustomerParticipant(array $row, InstagramAccount $account): ?array
    {
        foreach ((array) data_get($row, 'participants.data', []) as $participant) {
            if (! is_array($participant)) {
                continue;
            }

            if ($this->participantIsBusiness($participant, $account)) {
                continue;
            }

            $id = trim((string) ($participant['id'] ?? ''));
            if ($id === '') {
                continue;
            }

            $username = isset($participant['username']) ? trim((string) $participant['username']) : null;

            return [
                'id' => $id,
                'username' => $username !== '' ? $username : null,
            ];
        }

        return null;
    }

    /**
     * @param  array{id: string, participant_id: string, participant_username: ?string, updated_time: string}  $thread
     * @return array{imported_inbound: int, imported_outbound: int, replied: int}
     */
    private function reconcileThread(
        InstagramAccount $account,
        string $token,
        array $thread,
        bool $allowAutoReply = true,
    ): array {
        $result = [
            'imported_inbound' => 0,
            'imported_outbound' => 0,
            'replied' => 0,
        ];

        $threadId = $thread['id'];
        $participantId = $thread['participant_id'];

        if ($threadId === '' || $participantId === '') {
            return $result;
        }

        $messages = $this->fetchThreadMessages($threadId, $token);
        if ($messages === []) {
            return $result;
        }

        // Ensure username on existing CRM dialog even when nothing new to import.
        $conversation = Conversation::query()
            ->where('channel', 'instagram')
            ->where('participant_id', $participantId)
            ->first();

        if ($conversation !== null && blank($conversation->participant_username) && filled($thread['participant_username'])) {
            $conversation->forceFill([
                'participant_username' => $thread['participant_username'],
            ])->save();
        }

        $businessMessages = [];
        foreach ($messages as $message) {
            if ($this->messageIsFromBusiness($message, $account)) {
                $businessMessages[] = $message;
            }
        }

        // Oldest first so outbound exists in CRM before a later inbound auto-reply check.
        usort($messages, static function (array $a, array $b): int {
            return strcmp((string) ($a['created_time'] ?? ''), (string) ($b['created_time'] ?? ''));
        });

        foreach ($messages as $message) {
            $mid = trim((string) ($message['id'] ?? ''));
            if ($mid === '' || ConversationMessage::query()->where('external_id', $mid)->exists()) {
                continue;
            }

            $createdAt = Carbon::parse((string) ($message['created_time'] ?? now()->toIso8601String()));
            $body = trim((string) ($message['message'] ?? ''));
            $attachments = $this->mediaResolver->extractImageAttachments($message);

            if ($this->messageIsFromBusiness($message, $account)) {
                $this->echoService->handle([
                    'recipient_id' => $participantId,
                    'message_id' => $mid,
                    'text' => $body,
                    'timestamp' => $createdAt->getTimestamp(),
                ]);
                $result['imported_outbound']++;

                continue;
            }

            $hasLaterOutbound = $this->metaHasOutboundAtOrAfter($businessMessages, $createdAt)
                || $this->crmHasOutboundAtOrAfter($participantId, $createdAt);

            $withinReplyWindow = $createdAt->gte(now()->subHours(self::AUTO_REPLY_MAX_AGE_HOURS));
            $autoReply = $allowAutoReply && $withinReplyWindow && ! $hasLaterOutbound;

            $beforeOutboundCount = $this->outboundCountForParticipant($participantId);

            $this->incomingService->handle([
                'sender_id' => $participantId,
                'message_id' => $mid,
                'text' => $body,
                'timestamp' => $createdAt->getTimestamp(),
                'attachments' => $attachments,
                'auto_reply' => $autoReply,
                'resolve_media' => $body === '' && $attachments === [],
            ]);

            if (! ConversationMessage::query()->where('external_id', $mid)->exists()) {
                continue;
            }

            $result['imported_inbound']++;

            $didReply = $autoReply && $this->outboundCountForParticipant($participantId) > $beforeOutboundCount;
            if ($didReply) {
                $result['replied']++;
            }

            try {
                Log::info('Instagram reconcile imported inbound.', [
                    'participant_id' => $participantId,
                    'username' => $thread['participant_username'],
                    'message_id' => $mid,
                    'auto_reply' => $autoReply,
                    'replied' => $didReply,
                ]);
            } catch (Throwable) {
                // Logging must not abort an otherwise successful import.
            }
        }

        return $result;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchThreadMessages(string $threadId, string $token): array
    {
        $response = $this->graph->get($token, $threadId, [
            'fields' => 'messages.limit('.self::MESSAGES_PER_THREAD.'){id,created_time,from,to,message,attachments,shares}',
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException(
                'Thread messages failed: HTTP '.$response->status().' '.$response->body()
            );
        }

        $rows = [];
        foreach ((array) data_get($response->json(), 'messages.data', []) as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $participant
     */
    private function participantIsBusiness(array $participant, InstagramAccount $account): bool
    {
        $id = trim((string) ($participant['id'] ?? ''));
        $username = strtolower(ltrim((string) ($participant['username'] ?? ''), '@'));
        $accountIds = $this->businessIds($account);
        $accountUsername = $this->businessUsername($account);

        if ($id !== '' && in_array($id, $accountIds, true)) {
            return true;
        }

        return $accountUsername !== '' && $username === $accountUsername;
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function messageIsFromBusiness(array $message, InstagramAccount $account): bool
    {
        $from = data_get($message, 'from');
        if (! is_array($from)) {
            return false;
        }

        return $this->participantIsBusiness($from, $account);
    }

    /**
     * @return list<string>
     */
    private function businessIds(InstagramAccount $account): array
    {
        $ids = $account->ownedInstagramIds();

        return array_values(array_filter(array_unique($ids)));
    }

    private function businessUsername(InstagramAccount $account): string
    {
        return strtolower(ltrim((string) data_get(
            $account->settings,
            'instagram_username',
            $account->name,
        ), '@'));
    }

    /**
     * @param  list<array<string, mixed>>  $businessMessages
     */
    private function metaHasOutboundAtOrAfter(array $businessMessages, Carbon $inboundAt): bool
    {
        foreach ($businessMessages as $message) {
            $created = Carbon::parse((string) ($message['created_time'] ?? ''));
            if ($created->gt($inboundAt)) {
                return true;
            }
        }

        return false;
    }

    private function crmHasOutboundAtOrAfter(string $participantId, Carbon $inboundAt): bool
    {
        $conversation = Conversation::query()
            ->where('channel', 'instagram')
            ->where('participant_id', $participantId)
            ->first();

        if ($conversation === null) {
            return false;
        }

        return ConversationMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', ConversationMessage::DIRECTION_OUTBOUND)
            ->where('sent_at', '>', $inboundAt)
            ->exists();
    }

    private function outboundCountForParticipant(string $participantId): int
    {
        $conversation = Conversation::query()
            ->where('channel', 'instagram')
            ->where('participant_id', $participantId)
            ->first();

        if ($conversation === null) {
            return 0;
        }

        return ConversationMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', ConversationMessage::DIRECTION_OUTBOUND)
            ->count();
    }
}
