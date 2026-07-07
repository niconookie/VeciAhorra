<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Cart\Requests;

use InvalidArgumentException;

final class CartItemCreateRequest
{
    public function __construct(private array $input)
    {
    }

    public function validated(): array
    {
        $data = $this->input;
        $data['inventory_id'] = $this->positiveInteger('inventory_id');
        $data['quantity'] = $this->positiveInteger('quantity');

        return $data;
    }

    private function positiveInteger(string $field): int
    {
        $value = $this->input[$field] ?? null;

        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }

        if (! is_int($value) || $value <= 0) {
            throw new InvalidArgumentException(
                "El campo {$field} debe ser un entero positivo."
            );
        }

        return $value;
    }
}
