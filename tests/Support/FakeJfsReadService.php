<?php

namespace Tests\Support;

use App\Services\Jfs\JfsReadService;

final class FakeJfsReadService extends JfsReadService
{
    /** @var list<array<string, mixed>> */
    public array $events = [];

    /** @var list<array<string, mixed>> */
    public array $lineups = [];

    public bool $configured = true;

    public bool $readFailed = false;

    public int $publicEventsCalls = 0;

    public int $publicBrandLineupsCalls = 0;

    public int $findClientByEmailCalls = 0;

    public int $writeCalls = 0;

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function lastReadFailed(): bool
    {
        return $this->readFailed || ! $this->configured;
    }

    public function publicEvents(): array
    {
        $this->publicEventsCalls++;

        if ($this->lastReadFailed()) {
            return [];
        }

        return $this->events;
    }

    public function publicBrandLineups(): array
    {
        $this->publicBrandLineupsCalls++;

        if ($this->lastReadFailed()) {
            return [];
        }

        return $this->lineups;
    }

    public function findClientByEmail(string $email): array
    {
        $this->findClientByEmailCalls++;

        return ['status' => 'not_found', 'client' => null, 'children' => []];
    }
}
