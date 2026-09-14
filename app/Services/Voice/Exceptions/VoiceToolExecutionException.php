<?php

namespace App\Services\Voice\Exceptions;

use RuntimeException;
use Throwable;

class VoiceToolExecutionException extends RuntimeException
{
    public function __construct(public readonly string $toolName, ?Throwable $previous = null)
    {
        parent::__construct('Voice tool execution failed', 0, $previous);
    }
}
