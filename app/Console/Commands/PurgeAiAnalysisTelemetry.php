<?php

namespace App\Console\Commands;

use App\Models\AiRun;
use App\Models\BotDecisionTrace;
use Illuminate\Console\Command;

class PurgeAiAnalysisTelemetry extends Command
{
    protected $signature = 'ai:purge-analysis-telemetry {--days=90}';

    protected $description = 'Delete expired AI prompts and decision traces';

    public function handle(): int
    {
        $before = now()->subDays(max(1, (int) $this->option('days')));

        AiRun::query()->where('created_at', '<', $before)->delete();
        BotDecisionTrace::query()->where('created_at', '<', $before)->delete();

        $this->components->info('Expired AI analysis telemetry deleted.');

        return self::SUCCESS;
    }
}
