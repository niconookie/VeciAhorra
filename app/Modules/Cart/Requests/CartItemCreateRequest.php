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
        if (array_key_exists('inventory_id', $data) || array_key_exists('store_id', $data) || array_key_exists('minimarket_id', $data)) {
            throw new InvalidArgumentException('La referencia pública de la oferta no es válida.');
        }
        $token = $data['offer_token'] ?? null;
        if (! is_string($token) || preg_match('/^[A-Za-z0-9_-]{40,512}$/D', $token) !== 1) {
            throw new InvalidArgumentException('La referencia pública de la oferta no es válida.');
        }
        $data = ['offer_token' => $token];
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
