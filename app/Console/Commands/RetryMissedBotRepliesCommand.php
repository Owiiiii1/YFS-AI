<?php

namespace App\Console\Commands;

use App\Services\Messaging\RetryMissedBotRepliesService;
use Illuminate\Console\Command;

class RetryMissedBotRepliesCommand extends Command
{
    protected $signature = 'bot:retry-missed-replies {conversation_id? : Retry a specific conversation} {--force : Ignore retry age and backoff limits} {--nudge : Generate a new bot reply even if the bot already spoke after the last inbound}';

    protected $description = 'Retry bot auto-replies when the last customer message is still unanswered';

    public function handle(RetryMissedBotRepliesService $service): int
    {
        $conversationId = $this->argument('conversation_id');
        $sent = $service->process(
            conversationId: $conversationId !== null ? (int) $conversationId : null,
            force: (bool) $this->option('force'),
            nudge: (bool) $this->option('nudge'),
        );

        $this->info("Missed bot replies sent: {$sent}");

        return self::SUCCESS;
    }
}
