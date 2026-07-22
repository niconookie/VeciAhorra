<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Products\Exceptions;

use RuntimeException;

final class ProductLifecycleException extends RuntimeException
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
