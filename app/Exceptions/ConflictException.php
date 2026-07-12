<?php

declare(strict_types=1);

namespace VeciAhorra\Exceptions;

use RuntimeException;

final class ConflictException extends RuntimeException
{
    public function __construct(
        string $message,
        private string $errorCode = 'state_conflict'
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
