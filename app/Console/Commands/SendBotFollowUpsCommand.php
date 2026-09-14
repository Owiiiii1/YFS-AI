<?php

namespace App\Console\Commands;

use App\Services\Instagram\BotFollowUpService;
use Illuminate\Console\Command;

class SendBotFollowUpsCommand extends Command
{
    protected $signature = 'bot:send-follow-ups';

    protected $description = 'Idle bot follow-ups are disabled; this command does nothing';

    public function handle(BotFollowUpService $followUpService): int
    {
        $followUpService->processDueReminders();

        $this->info('Bot follow-ups are disabled.');

        return self::SUCCESS;
    }
}
