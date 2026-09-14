<?php

namespace App\Services\Meta;

use RuntimeException;

class InvalidMetaSignedRequestException extends RuntimeException
{
    public const REASON_MALFORMED = 'malformed';

    public const REASON_INVALID_SIGNATURE = 'invalid_signature';

    public function __construct(
        public readonly string $reason,
        int $status = 400,
    ) {
        parent::__construct($reason, $status);
    }

    public function httpStatus(): int
    {
        return $this->getCode() >= 400 ? $this->getCode() : 400;
    }
}
