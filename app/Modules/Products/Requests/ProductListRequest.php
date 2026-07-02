<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Products\Requests;

use InvalidArgumentException;

/**
 * Valida y normaliza los parametros del listado de Productos.
 */
final class ProductListRequest
{
    private const DEFAULT_PAGE = 1;

    private const DEFAULT_PER_PAGE = 20;

    private const MAXIMUM_PER_PAGE = 100;

    private const DEFAULT_ORDER_BY = 'name';

    private const DEFAULT_DIRECTION = 'ASC';

    private const ALLOWED_STATUSES = [
        'active',
        'inactive',
    ];

    private const ALLOWED_ORDER_FIELDS = [
        'id',
        'name',
        'sku',
        'created_at',
        'updated_at',
    ];

    private const ALLOWED_DIRECTIONS = [
        'ASC',
        'DESC',
    ];

    /**
     * Datos crudos recibidos.
     */
    private array $input;

    /**
     * Errores de la ultima validacion.
     *
     * @var string[]
     */
    private array $errors = [];

    public function __construct(array $input)
    {
        $this->input = $input;
    }

    /**
     * Devuelve los parametros validados y normalizados.
     *
     * @return array{
     *     page: int,
     *     per_page: int,
     *     term: string|null,
     *     status: string|null,
     *     order_by: string,
     *     direction: string
     * }
     */
    public function validated(): array
    {
        $this->errors = [];

        $data = [
            'page' => $this->validatedPositiveInteger(
                'page',
                self::DEFAULT_PAGE
            ),
            'per_page' => $this->validatedPositiveInteger(
                'per_page',
                self::DEFAULT_PER_PAGE,
                self::MAXIMUM_PER_PAGE
            ),
            'term' => $this->validatedTerm(),
            'status' => $this->validatedStatus(),
            'order_by' => $this->validatedOrderBy(),
            'direction' => $this->validatedDirection(),
        ];

        $this->throwIfInvalid();

        return $data;
    }

    /**
     * Devuelve los errores de la ultima validacion.
     *
     * @return list<string>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Valida un entero positivo con limite superior opcional.
     */
    private function validatedPositiveInteger(
        string $field,
        int $default,
        ?int $maximum = null
    ): int {
        if (! $this->has($field)) {
            return $default;
        }

        $value = $this->value($field);

        if (! $this->isPositiveInteger($value)) {
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

    /**
     * Valida el termino opcional de busqueda.
     */
    private function validatedTerm(): ?string
    {
        if (! $this->has('term')) {
            return null;
        }

        $value = $this->value('term');

        if (! is_string($value)) {
            $this->errors[] =
                'El parametro term debe ser texto.';

            return null;
        }

        $term = trim($value);

        return $term === '' ? null : $term;
    }

    /**
     * Valida el estado opcional del producto.
     */
    private function validatedStatus(): ?string
    {
        if (! $this->has('status')) {
            return null;
        }

        $value = $this->value('status');

        if (! is_string($value)) {
            $this->errors[] =
                'El parametro status debe ser texto.';

            return null;
        }

        $status = strtolower(trim($value));

        return $this->validateEnum(
            'status',
            $status,
            self::ALLOWED_STATUSES,
            null
        );
    }

    /**
     * Valida el campo de ordenamiento.
     */
    private function validatedOrderBy(): string
    {
        if (! $this->has('order_by')) {
            return self::DEFAULT_ORDER_BY;
        }

        $value = $this->value('order_by');

        if (! is_string($value)) {
            $this->errors[] =
                'El parametro order_by debe ser texto.';

            return self::DEFAULT_ORDER_BY;
        }

        return $this->validateEnum(
            'order_by',
            $value,
            self::ALLOWED_ORDER_FIELDS,
            self::DEFAULT_ORDER_BY
        );
    }

    /**
     * Valida y normaliza la direccion del ordenamiento.
     */
    private function validatedDirection(): string
    {
        if (! $this->has('direction')) {
            return self::DEFAULT_DIRECTION;
        }

        $value = $this->value('direction');

        if (! is_string($value)) {
            $this->errors[] =
                'El parametro direction debe ser texto.';

            return self::DEFAULT_DIRECTION;
        }

        $direction = strtoupper(trim($value));

        return $this->validateEnum(
            'direction',
            $direction,
            self::ALLOWED_DIRECTIONS,
            self::DEFAULT_DIRECTION
        );
    }

    /**
     * Valida que un valor pertenezca a una lista permitida.
     *
     * @template T of string|null
     * @param string[] $allowed
     * @param T $fallback
     * @return string|T
     */
    private function validateEnum(
        string $field,
        string $value,
        array $allowed,
        ?string $fallback
    ): ?string {
        if (in_array($value, $allowed, true)) {
            return $value;
        }

        $this->errors[] = sprintf(
            'El parametro %s no es valido.',
            $field
        );

        return $fallback;
    }

    /**
     * Indica si un campo fue recibido.
     */
    private function has(string $field): bool
    {
        return array_key_exists($field, $this->input);
    }

    /**
     * Obtiene un valor sin barras agregadas por WordPress.
     */
    private function value(string $field): mixed
    {
        $value = $this->input[$field] ?? null;

        return is_string($value)
            ? wp_unslash($value)
            : $value;
    }

    /**
     * Indica si un valor representa un entero positivo de PHP.
     */
    private function isPositiveInteger(mixed $value): bool
    {
        if (is_int($value)) {
            return $value >= 1;
        }

        if (! is_string($value)) {
            return false;
        }

        $value = trim($value);

        if ($value === '' || ! ctype_digit($value)) {
            return false;
        }

        $value = ltrim($value, '0');

        if ($value === '') {
            return false;
        }

        $maximum = (string) PHP_INT_MAX;
        $length = strlen($value);
        $maximumLength = strlen($maximum);

        if ($length !== $maximumLength) {
            return $length < $maximumLength;
        }

        return strcmp($value, $maximum) <= 0;
    }

    /**
     * Lanza una excepcion si la validacion fallo.
     */
    private function throwIfInvalid(): void
    {
        if ($this->errors === []) {
            return;
        }

        throw new InvalidArgumentException(
            implode(' ', $this->errors)
        );
    }
}
