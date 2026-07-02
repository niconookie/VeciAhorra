<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Products\Requests;

use InvalidArgumentException;

/**
 * Valida y normaliza operaciones masivas de Productos.
 */
final class ProductBulkRequest
{
    private const MAXIMUM_IDS = 1000;

    private const ALLOWED_STATUSES = [
        'active',
        'inactive',
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
     * Valida una actualizacion masiva de estado.
     *
     * @return array{ids: list<int>, status: string}
     */
    public function validateForStatus(): array
    {
        $this->errors = [];

        $data = [
            'ids' => $this->validatedIds(),
            'status' => $this->validatedStatus(),
        ];

        $this->throwIfInvalid();

        return $data;
    }

    /**
     * Valida una asignacion masiva de categoria.
     *
     * @return array{ids: list<int>, category_id: int|null}
     */
    public function validateForCategory(): array
    {
        return $this->validateForRelation(
            'category_id',
            'categoria'
        );
    }

    /**
     * Valida una asignacion masiva de marca.
     *
     * @return array{ids: list<int>, brand_id: int|null}
     */
    public function validateForBrand(): array
    {
        return $this->validateForRelation(
            'brand_id',
            'marca'
        );
    }

    /**
     * Valida una asignacion masiva de unidad.
     *
     * @return array{ids: list<int>, unit_id: int|null}
     */
    public function validateForUnit(): array
    {
        return $this->validateForRelation(
            'unit_id',
            'unidad'
        );
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
     * Valida una asignacion masiva de una relacion nullable.
     *
     * @return array{ids: list<int>, category_id?: int|null, brand_id?: int|null, unit_id?: int|null}
     */
    private function validateForRelation(
        string $field,
        string $label
    ): array {
        $this->errors = [];

        $data = [
            'ids' => $this->validatedIds(),
            $field => $this->validatedNullableId(
                $field,
                $label
            ),
        ];

        $this->throwIfInvalid();

        return $data;
    }

    /**
     * Valida y normaliza la lista de productos.
     *
     * @return list<int>
     */
    private function validatedIds(): array
    {
        if (! $this->has('ids')) {
            $this->errors[] =
                'El campo ids es obligatorio.';

            return [];
        }

        $values = $this->input['ids'];

        if (! is_array($values) || ! array_is_list($values)) {
            $this->errors[] =
                'El campo ids debe ser una lista.';

            return [];
        }

        if ($values === []) {
            $this->errors[] =
                'El campo ids no puede estar vacio.';

            return [];
        }

        if (count($values) > self::MAXIMUM_IDS) {
            $this->errors[] = sprintf(
                'El campo ids no puede contener mas de %d elementos.',
                self::MAXIMUM_IDS
            );
        }

        $ids = [];
        $seen = [];

        foreach ($values as $index => $value) {
            $value = is_string($value)
                ? wp_unslash($value)
                : $value;

            if (! $this->isPositiveInteger($value)) {
                $this->errors[] = sprintf(
                    'El elemento ids[%d] debe ser un entero positivo.',
                    $index
                );

                continue;
            }

            $id = (int) $value;

            if (isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $ids[] = $id;
        }

        return $ids;
    }

    /**
     * Valida el estado objetivo.
     */
    private function validatedStatus(): string
    {
        if (! $this->has('status')) {
            $this->errors[] =
                'El campo status es obligatorio.';

            return '';
        }

        $value = $this->value('status');

        if (! is_string($value)) {
            $this->errors[] =
                'El campo status debe ser texto.';

            return '';
        }

        $status = strtolower(trim($value));

        if (! in_array($status, self::ALLOWED_STATUSES, true)) {
            $this->errors[] =
                'El estado debe ser active o inactive.';
        }

        return $status;
    }

    /**
     * Valida un identificador relacional obligatorio y nullable.
     */
    private function validatedNullableId(
        string $field,
        string $label
    ): ?int {
        if (! $this->has($field)) {
            $this->errors[] = sprintf(
                'El campo %s es obligatorio.',
                $field
            );

            return null;
        }

        $value = $this->value($field);

        if ($value === null) {
            return null;
        }

        if (! $this->isPositiveInteger($value)) {
            $this->errors[] = sprintf(
                'El campo %s debe ser un entero positivo o null.',
                $label
            );

            return null;
        }

        return (int) $value;
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
