<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Inventory\Exceptions;

use InvalidArgumentException;
use Throwable;

/**
 * Error administrativo de Inventory asociado a un campo concreto.
 */
final class InventoryValidationException extends InvalidArgumentException
{
    public function __construct(
        string $message,
        private string $field,
        private string $reason,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function field(): string
    {
        return $this->field;
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
