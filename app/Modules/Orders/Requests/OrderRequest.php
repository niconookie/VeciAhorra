<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Requests;

use InvalidArgumentException;

/**
 * Valida y normaliza el payload de creacion de un pedido.
 */
final class OrderRequest
{
    private array $input;

    /** @var list<string> */
    private array $errors = [];

    public function __construct(array $input)
    {
        $this->input = $input;
    }

    /**
     * @return array{
     *     customer_id: int,
     *     minimarket_id: int,
     *     items: list<array{
     *         product_id: int,
     *         inventory_id: int,
     *         quantity: int
     *     }>
     * }
     */
    public function validated(): array
    {
        $this->errors = [];

        $data = [
            'customer_id' => $this->validatedRequiredId('customer_id'),
            'minimarket_id' => $this->validatedRequiredId('minimarket_id'),
            'items' => $this->validatedItems(),
        ];

        $this->throwIfInvalid();

        return $data;
    }

    /** @return list<string> */
    public function errors(): array
    {
        return $this->errors;
    }

    private function validatedRequiredId(string $field): int
    {
        if (! array_key_exists($field, $this->input)) {
            $this->errors[] = sprintf(
                'El campo %s es obligatorio.',
                $field
            );

            return 0;
        }

        $value = $this->value($this->input[$field]);

        if (! $this->isPositiveInteger($value)) {
            $this->errors[] = sprintf(
                'El campo %s debe ser un entero positivo.',
                $field
            );

            return 0;
        }

        return (int) $value;
    }

    /**
     * @return list<array{product_id: int, inventory_id: int, quantity: int}>
     */
    private function validatedItems(): array
    {
        if (! array_key_exists('items', $this->input)) {
            $this->errors[] = 'El campo items es obligatorio.';

            return [];
        }

        $items = $this->input['items'];

        if (! is_array($items) || ! array_is_list($items)) {
            $this->errors[] = 'El campo items debe ser una lista.';

            return [];
        }

        if ($items === []) {
            $this->errors[] = 'El campo items no puede estar vacio.';

            return [];
        }

        $normalized = [];

        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                $this->errors[] = sprintf(
                    'El elemento items[%d] debe ser un objeto.',
                    $index
                );

                continue;
            }

            $normalized[] = [
                'product_id' => $this->validatedItemId(
                    $item,
                    $index,
                    'product_id'
                ),
                'inventory_id' => $this->validatedItemId(
                    $item,
                    $index,
                    'inventory_id'
                ),
                'quantity' => $this->validatedItemId(
                    $item,
                    $index,
                    'quantity'
                ),
            ];
        }

        return $normalized;
    }

    private function validatedItemId(
        array $item,
        int $index,
        string $field
    ): int {
        if (! array_key_exists($field, $item)) {
            $this->errors[] = sprintf(
                'El campo items[%d].%s es obligatorio.',
                $index,
                $field
            );

            return 0;
        }

        $value = $this->value($item[$field]);

        if (! $this->isPositiveInteger($value)) {
            $this->errors[] = sprintf(
                'El campo items[%d].%s debe ser un entero positivo.',
                $index,
                $field
            );

            return 0;
        }

        return (int) $value;
    }

    private function value(mixed $value): mixed
    {
        return is_string($value) ? wp_unslash($value) : $value;
    }

    private function isPositiveInteger(mixed $value): bool
    {
        if (is_int($value)) {
            return $value > 0;
        }

        if (! is_string($value)) {
            return false;
        }

        $value = trim($value);

        if ($value === '' || ! ctype_digit($value)) {
            return false;
        }

        $normalized = ltrim($value, '0');

        if ($normalized === '') {
            return false;
        }

        $maximum = (string) PHP_INT_MAX;
        $length = strlen($normalized);
        $maximumLength = strlen($maximum);

        if ($length !== $maximumLength) {
            return $length < $maximumLength;
        }

        return strcmp($normalized, $maximum) <= 0;
    }

    private function throwIfInvalid(): void
    {
        if ($this->errors !== []) {
            throw new InvalidArgumentException(
                implode(' ', $this->errors)
            );
        }
    }
}
