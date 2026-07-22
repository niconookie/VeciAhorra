<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Products\Requests;

use DateTimeImmutable;
use InvalidArgumentException;
use VeciAhorra\Modules\Products\Models\Product;

/**
 * Valida y normaliza los datos de entrada de Productos.
 */
final class ProductRequest
{
    /**
     * Datos crudos recibidos.
     */
    private array $input;

    /**
     * Errores de la última validación.
     *
     * @var string[]
     */
    private array $errors = [];

    public function __construct(array $input)
    {
        $this->input = $input;
    }

    /**
     * Valida los datos para crear un producto.
     *
     * @return array{
     *     name: string,
     *     sku: string|null,
     *     description: string|null,
     *     woo_product_id: int|null,
     *     category_id: int|null,
     *     brand_id: int|null,
     *     unit_id: int|null,
     *     image_id: int|null
     * }
     */
    public function validateForCreate(): array
    {
        $this->errors = [];

        $data = [
            'name' => $this->validatedName(true),
            'sku' => $this->validatedOptionalText(
                'sku',
                'SKU',
                100
            ),
            'description' => $this->validatedDescription(),
            'woo_product_id' => $this->validatedOptionalId(
                'woo_product_id',
                'Referencia de WooCommerce'
            ),
            'category_id' => $this->validatedOptionalId(
                'category_id',
                'Categoría'
            ),
            'brand_id' => $this->validatedOptionalId(
                'brand_id',
                'Marca'
            ),
            'unit_id' => $this->validatedOptionalId(
                'unit_id',
                'Unidad'
            ),
            'image_id' => $this->validatedOptionalId(
                'image_id',
                'Imagen'
            ),
        ];

        $this->throwIfInvalid();

        return $data;
    }

    /**
     * Valida los datos para actualizar un producto.
     *
     * Funciona como un PATCH parcial: solo devuelve los campos
     * recibidos y permite un payload vacío.
     *
     * @return array{
     *     name?: string,
     *     sku?: string|null,
     *     description?: string|null,
     *     woo_product_id?: int|null,
     *     category_id?: int|null,
     *     brand_id?: int|null,
     *     unit_id?: int|null,
     *     image_id?: int|null
     * }
     */
    public function validateForUpdate(): array
    {
        $this->errors = [];
        $this->rejectUnexpectedFields([
            'woo_product_id',
            'name',
            'sku',
            'description',
            'category_id',
            'brand_id',
            'unit_id',
            'image_id',
            'expected_updated_at',
        ]);
        $data = [];

        if ($this->has('name')) {
            $data['name'] = $this->validatedName(false);
        }

        if ($this->has('sku')) {
            $data['sku'] = $this->validatedOptionalText(
                'sku',
                'SKU',
                100
            );
        }

        if ($this->has('description')) {
            $data['description'] = $this->validatedDescription();
        }

        $idFields = [
            'woo_product_id' => 'Referencia de WooCommerce',
            'category_id' => 'Categoría',
            'brand_id' => 'Marca',
            'unit_id' => 'Unidad',
            'image_id' => 'Imagen',
        ];

        foreach ($idFields as $field => $label) {
            if ($this->has($field)) {
                $data[$field] = $this->validatedOptionalId(
                    $field,
                    $label
                );
            }
        }

        $this->throwIfInvalid();

        return $data;
    }

    /**
     * Valida un cambio de estado.
     *
     * @return array{status: string}
     */
    public function validateForStatusChange(): array
    {
        $this->errors = [];
        $this->rejectUnexpectedFields([
            'status',
            'expected_updated_at',
        ]);
        $value = $this->value('status');

        if (! is_string($value)) {
            $this->errors[] = 'El estado del producto es obligatorio.';
            $status = '';
        } else {
            $status = sanitize_key($value);

            if (! in_array(
                $status,
                Product::allowedStatuses(),
                true
            )) {
                $this->errors[] = 'El estado del producto no es válido.';
            }
        }

        $this->throwIfInvalid();

        return ['status' => $status];
    }

    /**
     * Valida la version durable requerida por las escrituras CAS.
     */
    public function validateExpectedUpdatedAt(): string
    {
        $this->errors = [];
        $value = $this->value('expected_updated_at');

        if (! is_string($value)) {
            $this->errors[] =
                'La version esperada del producto es obligatoria.';
            $expectedUpdatedAt = '';
        } else {
            $expectedUpdatedAt = trim($value);
            $date = DateTimeImmutable::createFromFormat(
                '!Y-m-d H:i:s',
                $expectedUpdatedAt
            );

            if (
                $date === false
                || $date->format('Y-m-d H:i:s') !== $expectedUpdatedAt
            ) {
                $this->errors[] =
                    'La version esperada del producto no es valida.';
            }
        }

        $this->throwIfInvalid();

        return $expectedUpdatedAt;
    }

    /**
     * Devuelve los errores de la última validación.
     *
     * @return list<string>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Valida el nombre del producto.
     */
    private function validatedName(bool $required): ?string
    {
        if (! $this->has('name')) {
            if ($required) {
                $this->errors[] =
                    'El nombre del producto es obligatorio.';
            }

            return null;
        }

        $value = $this->value('name');

        if (! is_string($value)) {
            $this->errors[] =
                'El nombre del producto debe ser texto.';

            return null;
        }

        $name = trim(sanitize_text_field($value));

        if ($name === '') {
            $this->errors[] =
                'El nombre del producto es obligatorio.';

            return null;
        }

        if ($this->length($name) > 180) {
            $this->errors[] =
                'El nombre del producto supera el máximo de 180 caracteres.';
        }

        return $name;
    }

    /**
     * Valida un texto opcional de una línea.
     */
    private function validatedOptionalText(
        string $field,
        string $label,
        int $maximum
    ): ?string {
        if (! $this->has($field)) {
            return null;
        }

        $value = $this->value($field);

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            $this->errors[] = sprintf(
                'El campo %s debe ser texto.',
                $label
            );

            return null;
        }

        $value = trim(sanitize_text_field($value));

        if ($value === '') {
            return null;
        }

        if ($this->length($value) > $maximum) {
            $this->errors[] = sprintf(
                'El campo %s supera el máximo de %d caracteres.',
                $label,
                $maximum
            );
        }

        return $value;
    }

    /**
     * Valida la descripción opcional.
     */
    private function validatedDescription(): ?string
    {
        if (! $this->has('description')) {
            return null;
        }

        $value = $this->value('description');

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            $this->errors[] =
                'La descripción debe ser texto.';

            return null;
        }

        $description = trim(
            sanitize_textarea_field($value)
        );

        return $description === ''
            ? null
            : $description;
    }

    /**
     * Valida un identificador opcional.
     */
    private function validatedOptionalId(
        string $field,
        string $label
    ): ?int {
        if (! $this->has($field)) {
            return null;
        }

        $value = $this->value($field);

        if (
            $value === null
            || $value === ''
            || (is_string($value) && trim($value) === '')
        ) {
            return null;
        }

        $isPositiveInteger =
            is_int($value) && $value > 0;

        if (is_string($value)) {
            $value = trim($value);
            $isPositiveInteger = ctype_digit($value)
                && (int) $value > 0;
        }

        if (! $isPositiveInteger) {
            $this->errors[] = sprintf(
                'El campo %s debe ser un entero positivo.',
                $label
            );

            return null;
        }

        return absint($value);
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
     * Obtiene la longitud de un texto.
     */
    private function length(string $value): int
    {
        return function_exists('mb_strlen')
            ? mb_strlen($value)
            : strlen($value);
    }

    /** @param list<string> $allowedFields */
    private function rejectUnexpectedFields(array $allowedFields): void
    {
        foreach (array_keys($this->input) as $field) {
            if (! in_array($field, $allowedFields, true)) {
                $this->errors[] = sprintf(
                    'El campo %s no pertenece a esta operacion.',
                    (string) $field
                );
            }
        }
    }

    /**
     * Lanza una excepción si la validación falló.
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
