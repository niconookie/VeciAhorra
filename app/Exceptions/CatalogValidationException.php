<?php

declare(strict_types=1);

namespace VeciAhorra\Exceptions;

use InvalidArgumentException;

final class CatalogValidationException extends InvalidArgumentException
{
    public function __construct(
        private string $errorCode,
        string $message
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
