<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Inventory\Requests;

use InvalidArgumentException;

/**
 * Valida y normaliza actualizaciones parciales de inventario.
 */
final class InventoryUpdateRequest
{
    private const ALLOWED_STATUSES = [
        'active',
        'inactive',
    ];

    private array $input;

    /** @var list<string> */
    private array $errors = [];

    public function __construct(array $input)
    {
        $this->input = $input;
    }

    /**
     * @return array{price?: float, stock?: int, status?: string}
     */
    public function validated(): array
    {
        $this->errors = [];
        $data = [];

        foreach (['product_id', 'minimarket_id'] as $field) {
            if ($this->has($field)) {
                $this->errors[] = sprintf(
                    'El campo %s no se puede actualizar.',
                    $field
                );
            }
        }

        if ($this->has('price')) {
            $data['price'] = $this->validatedPrice();
        }

        if ($this->has('stock')) {
            $data['stock'] = $this->validatedStock();
        }

        if ($this->has('status')) {
            $data['status'] = $this->validatedStatus();
        }

        if ($data === [] && $this->errors === []) {
            $this->errors[] =
                'La actualizacion requiere al menos un campo permitido.';
        }

        $this->throwIfInvalid();

        return $data;
    }

    /** @return list<string> */
    public function errors(): array
    {
        return $this->errors;
    }

    private function validatedPrice(): float
    {
        $value = $this->value('price');

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
            $this->errors[] =
                'El campo price debe ser mayor o igual a 0.';

            return 0.0;
        }

        return $price;
    }

    private function validatedStock(): int
    {
        $value = $this->value('stock');

        if (! $this->isInteger($value, 0)) {
            $this->errors[] =
                'El campo stock debe ser un entero mayor o igual a 0.';

            return 0;
        }

        return (int) $value;
    }

    private function validatedStatus(): string
    {
        $value = $this->value('status');

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
            throw new InvalidArgumentException(
                implode(' ', $this->errors)
            );
        }
    }
}
