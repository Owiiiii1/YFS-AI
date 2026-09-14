<?php

namespace App\Console\Commands;

use App\Services\Bot\OperatorHandoffRecoveryService;
use Illuminate\Console\Command;

class RecoverOperatorHandoffs extends Command
{
    protected $signature = 'bot:recover-operator-handoffs';

    protected $description = 'Finish operator handoffs that the bot claimed but did not complete';

    public function handle(OperatorHandoffRecoveryService $recovery): int
    {
        $recovered = $recovery->recoverDue();
        $this->components->info("Operator handoffs recovered: {$recovered}");

        return self::SUCCESS;
    }
}
