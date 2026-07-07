<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Cart\Requests;

use InvalidArgumentException;

final class CartItemQuantityRequest
{
    public function __construct(private array $input)
    {
    }

    public function validated(): array
    {
        $quantity = $this->input['quantity'] ?? null;

        if (is_string($quantity) && ctype_digit($quantity)) {
            $quantity = (int) $quantity;
        }

        if (! is_int($quantity) || $quantity <= 0) {
            throw new InvalidArgumentException(
                'El campo quantity debe ser un entero positivo.'
            );
        }

        return ['quantity' => $quantity];
    }
}
