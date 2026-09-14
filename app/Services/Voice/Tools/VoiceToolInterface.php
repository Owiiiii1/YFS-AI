<?php

namespace App\Services\Voice\Tools;

interface VoiceToolInterface
{
    public function name(): string;

    public function description(): string;

    /**
     * JSON Schema for tool arguments. Must not include credentials.
     *
     * @return array<string, mixed>
     */
    public function inputSchema(): array;

    /**
     * JSON-serializable tool result. Must not include credentials.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function execute(array $arguments): array;
}
