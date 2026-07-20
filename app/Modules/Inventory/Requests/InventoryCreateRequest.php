<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Inventory\Requests;

use InvalidArgumentException;
use VeciAhorra\Modules\Inventory\Exceptions\InventoryValidationException;

/**
 * Valida y normaliza la creacion de inventario.
 */
final class InventoryCreateRequest
{
    private const ALLOWED_STATUSES = [
        'active',
        'inactive',
    ];

    private array $input;

    /** @var list<string> */
    private array $errors = [];

    private ?string $referenceErrorField = null;

    public function __construct(array $input)
    {
        $this->input = $input;
    }

    /**
     * @return array{
     *     product_id: int,
     *     minimarket_id: int,
     *     price: float,
     *     stock: int,
     *     status: string
     * }
     */
    public function validated(): array
    {
        $this->errors = [];
        $this->referenceErrorField = null;

        $data = [
            'product_id' => $this->validatedRequiredId('product_id'),
            'minimarket_id' => $this->validatedRequiredId('minimarket_id'),
            'price' => $this->validatedPrice('price'),
            'stock' => $this->has('stock')
                ? $this->validatedStock('stock')
                : 0,
            'status' => $this->has('status')
                ? $this->validatedStatus('status')
                : 'active',
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
        if (! $this->has($field)) {
            $this->rememberReferenceError($field);
            $this->errors[] = sprintf(
                'El campo %s es obligatorio.',
                $field
            );

            return 0;
        }

        $value = $this->value($field);

        if (! $this->isInteger($value, 1)) {
            $this->rememberReferenceError($field);
            $this->errors[] = sprintf(
                'El campo %s debe ser un entero positivo.',
                $field
            );

            return 0;
        }

        return (int) $value;
    }

    private function validatedPrice(string $field): float
    {
        if (! $this->has($field)) {
            $this->errors[] = 'El campo price es obligatorio.';

            return 0.0;
        }

        $value = $this->value($field);

        if (is_string($value)) {
            $value = trim($value);
        }

        if (
            $value === ''
            || is_bool($value)
            || ! is_numeric($value)
        ) {
            $this->errors[] = 'El campo price debe ser numerico.';

            return 0.0;
        }

        $price = (float) $value;

        if (! is_finite($price) || $price < 0) {
            $this->errors[] = 'El campo price debe ser mayor o igual a 0.';

            return 0.0;
        }

        return $price;
    }

    private function validatedStock(string $field): int
    {
        $value = $this->value($field);

        if (! $this->isInteger($value, 0)) {
            $this->errors[] =
                'El campo stock debe ser un entero mayor o igual a 0.';

            return 0;
        }

        return (int) $value;
    }

    private function validatedStatus(string $field): string
    {
        $value = $this->value($field);

        if (! is_string($value)) {
            $this->errors[] = 'El campo status debe ser texto.';

            return '';
        }

        $status = strtolower(trim($value));

        if (! in_array($status, self::ALLOWED_STATUSES, true)) {
            $this->errors[] = 'El campo status no es valido.';

            return '';
        }

        return $status;
    }

    private function has(string $field): bool
    {
        return array_key_exists($field, $this->input);
    }

    private function value(string $field): mixed
    {
        $value = $this->input[$field] ?? null;

        return is_string($value) ? wp_unslash($value) : $value;
    }

    private function isInteger(mixed $value, int $minimum): bool
    {
        if (is_int($value)) {
            return $value >= $minimum;
        }

        if (! is_string($value)) {
            return false;
        }

        $value = trim($value);

        if ($value === '' || ! ctype_digit($value)) {
            return false;
        }

        $normalized = ltrim($value, '0');
        $normalized = $normalized === '' ? '0' : $normalized;
        $maximum = (string) PHP_INT_MAX;

        if (
            strlen($normalized) > strlen($maximum)
            || (
                strlen($normalized) === strlen($maximum)
                && strcmp($normalized, $maximum) > 0
            )
        ) {
            return false;
        }

        return (int) $normalized >= $minimum;
    }

    private function throwIfInvalid(): void
    {
        if ($this->errors !== []) {
            if ($this->referenceErrorField !== null) {
                throw new InventoryValidationException(
                    implode(' ', $this->errors),
                    $this->referenceErrorField,
                    $this->referenceErrorField === 'product_id'
                        ? 'inventory_invalid_product_id'
                        : 'inventory_invalid_store_id'
                );
            }

            throw new InvalidArgumentException(
                implode(' ', $this->errors)
            );
        }
    }

    private function rememberReferenceError(string $field): void
    {
        if ($this->referenceErrorField !== null) {
            return;
        }

        $this->referenceErrorField = $field === 'minimarket_id'
            ? 'store_id'
            : 'product_id';
    }
}
