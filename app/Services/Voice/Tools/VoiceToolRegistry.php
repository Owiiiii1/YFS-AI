<?php

namespace App\Services\Voice\Tools;

use App\Services\Voice\Exceptions\UnknownVoiceToolException;

final class VoiceToolRegistry
{
    /** @var array<string, VoiceToolInterface> */
    private array $tools = [];

    /**
     * @param  iterable<VoiceToolInterface>  $tools
     */
    public function __construct(iterable $tools)
    {
        foreach ($tools as $tool) {
            $this->tools[$tool->name()] = $tool;
        }
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    public function get(string $name): VoiceToolInterface
    {
        if (! isset($this->tools[$name])) {
            throw new UnknownVoiceToolException($name);
        }

        return $this->tools[$name];
    }

    /**
     * @return list<VoiceToolInterface>
     */
    public function all(): array
    {
        return array_values($this->tools);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->tools);
    }
}
