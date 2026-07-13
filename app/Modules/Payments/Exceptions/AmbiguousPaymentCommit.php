<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Exceptions;

use RuntimeException;

final class AmbiguousPaymentCommit extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('El resultado del commit interno es ambiguo.');
    }
}
