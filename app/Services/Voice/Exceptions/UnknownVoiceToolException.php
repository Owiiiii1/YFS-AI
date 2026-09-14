<?php

namespace App\Services\Voice\Exceptions;

use RuntimeException;

class UnknownVoiceToolException extends RuntimeException
{
    public function __construct(public readonly string $toolName)
    {
        parent::__construct('Unknown voice tool');
    }
}
