<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Exceptions;

use RuntimeException;

final class PaymentConfirmationFailure extends RuntimeException
{
    public function __construct(
        public readonly string $resultCode,
        public readonly bool $retryable = false,
        public readonly string $severity = 'high'
    ) {
        parent::__construct('La confirmacion transaccional fue rechazada.');
    }
}
