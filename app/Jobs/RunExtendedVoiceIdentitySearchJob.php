<?php

namespace App\Jobs;

use App\Services\Voice\Identity\ExtendedVoiceIdentitySearchRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunExtendedVoiceIdentitySearchJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 45;

    /** @var list<int> */
    public array $backoff = [5, 15];

    public function __construct(
        public int $searchId,
    ) {}

    public function handle(ExtendedVoiceIdentitySearchRunner $runner): void
    {
        $runner->run($this->searchId);
    }
}
