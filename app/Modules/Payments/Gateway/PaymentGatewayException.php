<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Gateway;

use RuntimeException;
use Throwable;

final class PaymentGatewayException extends RuntimeException
{
    public function __construct(
        string $message,
        private string $errorCode,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
