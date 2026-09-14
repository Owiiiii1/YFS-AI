<?php

namespace App\Jobs;

use App\Services\Facebook\ProcessIncomingFacebookMessageService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessIncomingFacebookMessageJob implements ShouldQueue, ShouldBeUnique
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
     *     auto_reply?: bool
     * }  $payload
     */
    public function __construct(
        public array $payload,
    ) {}

    public function uniqueId(): string
    {
        return (string) ($this->payload['message_id'] ?? sha1((string) json_encode($this->payload)));
    }

    public function handle(ProcessIncomingFacebookMessageService $service): void
    {
        $service->handle($this->payload);
    }
}
