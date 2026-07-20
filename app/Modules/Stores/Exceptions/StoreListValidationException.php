<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Stores\Exceptions;

use InvalidArgumentException;

/**
 * Error estructurado de la consulta administrativa de Store.
 */
final class StoreListValidationException extends InvalidArgumentException
{
    public function __construct(
        private string $field,
        string $message
    ) {
        parent::__construct($message);
    }

    public function field(): string
    {
        return $this->field;
    }
}
