<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Products\Exceptions;

use RuntimeException;
use VeciAhorra\Modules\Products\Domain\ProductReferenceInspection;

final class ProductDeletionException extends RuntimeException
{
    public function __construct(
        private string $errorCode,
        string $message,
        private ProductReferenceInspection $inspection
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function inspection(): ProductReferenceInspection
    {
        return $this->inspection;
    }
}
