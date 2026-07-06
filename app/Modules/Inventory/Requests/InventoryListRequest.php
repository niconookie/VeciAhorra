<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Inventory\Requests;

use InvalidArgumentException;

/**
 * Valida y normaliza los filtros del listado de inventario.
 */
final class InventoryListRequest
{
    private const DEFAULT_PAGE = 1;

    private const DEFAULT_PER_PAGE = 20;

    private const MAXIMUM_PER_PAGE = 100;

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
     * @return array{
     *     page: int,
     *     per_page: int,
     *     search: string|null,
     *     product_id: int|null,
     *     minimarket_id: int|null,
     *     status: string|null
     * }
     */
    public function validated(): array
    {
        $this->errors = [];

        $data = [
            'page' => $this->validatedPagination(
                'page',
                self::DEFAULT_PAGE
            ),
            'per_page' => $this->validatedPagination(
                'per_page',
                self::DEFAULT_PER_PAGE,
                self::MAXIMUM_PER_PAGE
            ),
            'search' => $this->validatedSearch(),
            'product_id' => $this->validatedOptionalId('product_id'),
            'minimarket_id' => $this->validatedOptionalId('minimarket_id'),
            'status' => $this->validatedStatus(),
        ];

        $this->throwIfInvalid();

        return $data;
    }

    /** @return list<string> */
    public function errors(): array
    {
        return $this->errors;
    }

    private function validatedPagination(
        string $field,
        int $default,
        ?int $maximum = null
    ): int {
        if (! $this->has($field)) {
            return $default;
        }

        $value = $this->value($field);

        if (! $this->isInteger($value, 1)) {
            $this->errors[] = sprintf(
                'El parametro %s debe ser un entero mayor o igual a 1.',
                $field
            );

            return $default;
        }

        $integer = (int) $value;

        if ($maximum !== null && $integer > $maximum) {
            $this->errors[] = sprintf(
                'El parametro %s no puede ser mayor a %d.',
                $field,
                $maximum
            );

            return $default;
        }

        return $integer;
    }

    private function validatedSearch(): ?string
    {
        if (! $this->has('search')) {
            return null;
        }

        $value = $this->value('search');

        if (! is_string($value)) {
            $this->errors[] = 'El parametro search debe ser texto.';

            return null;
        }

        $search = trim($value);

        return $search === '' ? null : $search;
    }

    private function validatedOptionalId(string $field): ?int
    {
        if (! $this->has($field)) {
            return null;
        }

        $value = $this->value($field);

        if (
            $value === null
            || (is_string($value) && trim($value) === '')
        ) {
            return null;
        }

        if (! $this->isInteger($value, 1)) {
            $this->errors[] = sprintf(
                'El parametro %s debe ser un entero positivo.',
                $field
            );

            return null;
        }

        return (int) $value;
    }

    private function validatedStatus(): ?string
    {
        if (! $this->has('status')) {
            return null;
        }

        $value = $this->value('status');

        if (! is_string($value)) {
            $this->errors[] = 'El parametro status debe ser texto.';

            return null;
        }

        $status = strtolower(trim($value));

        if (! in_array($status, self::ALLOWED_STATUSES, true)) {
            $this->errors[] = 'El parametro status no es valido.';

            return null;
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
