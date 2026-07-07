<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Checkout\Requests;

use InvalidArgumentException;

/**
 * Valida el request minimo de la foundation de Checkout.
 */
final class CheckoutRequest
{
    public function __construct(private array $input)
    {
    }

    /** @return array<string, never> */
    public function validated(): array
    {
        if ($this->input !== []) {
            throw new InvalidArgumentException(
                'El request de checkout no admite campos en esta fase.'
            );
        }

        return [];
    }
}
