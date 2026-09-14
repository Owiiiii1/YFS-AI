<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Services\Instagram\InstagramConversationReconcileService;
use App\Services\Instagram\ProcessIncomingInstagramMessageService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessIncomingInstagramMessageJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 180;

    /** @var list<int> */
    public array $backoff = [15, 45, 90];

    public int $uniqueFor = 180;

    /**
     * @param  array{
     *     sender_id: string,
     *     message_id: string,
     *     text: string,
     *     timestamp?: int|string|null,
     *     attachments?: list<array{type?: string, url?: string, mime?: string|null}>,
     *     auto_reply?: bool,
     *     resolve_media?: bool
     * }  $payload
     */
    public function __construct(
        public array $payload,
    ) {}

    public function uniqueId(): string
    {
        return (string) ($this->payload['message_id'] ?? sha1((string) json_encode($this->payload)));
    }

    public function handle(
        InstagramConversationReconcileService $catchUp,
        ProcessIncomingInstagramMessageService $service,
    ): void {
        $senderId = trim((string) ($this->payload['sender_id'] ?? ''));

        if ($senderId !== '') {
            try {
                $catchUp->importForParticipant($senderId, allowAutoReply: false);
            } catch (Throwable $exception) {
                Log::warning('Instagram thread catch-up before inbound failed.', [
                    'sender_id' => $senderId,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        $service->handle($this->payload);

        if ($senderId === '') {
            return;
        }

        $conversation = Conversation::query()
            ->where('channel', 'instagram')
            ->where('participant_id', $senderId)
            ->first();

        if ($conversation !== null) {
            $service->retryLatestUnansweredInbound($conversation, force: true);
        }
    }
}
