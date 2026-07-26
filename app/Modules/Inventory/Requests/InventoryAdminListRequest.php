<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Inventory\Requests;

use InvalidArgumentException;

/**
 * Contrato estricto de filtros para la lectura operacional administrativa.
 */
final class InventoryAdminListRequest
{
    private const ALLOWED_KEYS = [
        'search',
        'product_id',
        'minimarket_id',
        'status',
        'availability',
        'cause',
        'reference',
        'page',
        'per_page',
        'order_by',
        'direction',
    ];

    public const CAUSES = [
        'product_reference_invalid',
        'store_reference_invalid',
        'product_missing',
        'store_missing',
        'reference_mismatch',
        'inventory_status_unknown',
        'inventory_inactive',
        'product_status_unknown',
        'product_not_public',
        'store_status_unknown',
        'store_not_active',
        'invalid_public_price',
        'out_of_stock',
        'publicly_available',
    ];

    private const STATUSES = ['active', 'inactive', 'unknown'];

    private const AVAILABILITIES = [
        'public',
        'not_public',
        'diagnostic_error',
    ];

    private const REFERENCES = [
        'active_reservation',
        'cart',
        'history',
        'none_known',
    ];

    private const ORDER_FIELDS = [
        'updated_at',
        'id',
        'product_name',
        'store_name',
        'price',
        'stock',
        'status',
    ];

    public function __construct(private array $input)
    {
    }

    /** @return array<string, mixed> */
    public function validated(): array
    {
        $unknown = array_diff(array_keys($this->input), self::ALLOWED_KEYS);

        if ($unknown !== []) {
            throw new InvalidArgumentException(sprintf(
                'Parametro administrativo desconocido: %s.',
                (string) reset($unknown)
            ));
        }

        $data = [
            'search' => $this->search(),
            'product_id' => $this->optionalId('product_id'),
            'minimarket_id' => $this->optionalId('minimarket_id'),
            'status' => $this->enum('status', self::STATUSES),
            'availability' => $this->enum(
                'availability',
                self::AVAILABILITIES
            ),
            'cause' => $this->enum('cause', self::CAUSES),
            'reference' => $this->enum('reference', self::REFERENCES),
            'page' => $this->positiveInteger('page', 1),
            'per_page' => $this->perPage(),
            'order_by' => $this->enum(
                'order_by',
                self::ORDER_FIELDS,
                'updated_at'
            ),
            'direction' => $this->enum(
                'direction',
                ['ASC', 'DESC'],
                'DESC',
                true
            ),
        ];

        $this->assertCompatible($data);

        return $data;
    }

    private function search(): ?string
    {
        if (! array_key_exists('search', $this->input)) {
            return null;
        }

        $value = $this->value('search');

        if (! is_string($value)) {
            throw new InvalidArgumentException(
                'El parametro search debe ser texto.'
            );
        }

        $value = trim($value);

        if (preg_match('//u', $value) !== 1) {
            throw new InvalidArgumentException(
                'El parametro search debe contener UTF-8 valido.'
            );
        }

        if (mb_strlen($value) > 100) {
            throw new InvalidArgumentException(
                'El parametro search no puede superar 100 caracteres.'
            );
        }

        if ($value === '') {
            return null;
        }

        if (
            preg_match(
                '/^(?:(?:inventory|product|store):)?[1-9][0-9]*$/Di',
                $value
            ) === 1
        ) {
            $separator = strrpos($value, ':');
            $id = $separator === false
                ? $value
                : substr($value, $separator + 1);

            if (filter_var($id, FILTER_VALIDATE_INT) === false) {
                throw new InvalidArgumentException(
                    'El identificador de search esta fuera de rango.'
                );
            }
        }

        return $value;
    }

    private function optionalId(string $field): ?int
    {
        if (! array_key_exists($field, $this->input)) {
            return null;
        }

        $value = $this->value($field);

        if (! is_string($value) && ! is_int($value)) {
            throw new InvalidArgumentException(
                "El parametro {$field} debe ser un entero positivo."
            );
        }

        $text = (string) $value;

        if (preg_match('/^[1-9][0-9]*$/D', $text) !== 1) {
            throw new InvalidArgumentException(
                "El parametro {$field} debe ser un entero positivo canonico."
            );
        }

        $number = filter_var($text, FILTER_VALIDATE_INT);

        if ($number === false || $number <= 0) {
            throw new InvalidArgumentException(
                "El parametro {$field} esta fuera de rango."
            );
        }

        return $number;
    }

    private function positiveInteger(string $field, int $default): int
    {
        if (! array_key_exists($field, $this->input)) {
            return $default;
        }

        $value = $this->value($field);
        $text = is_int($value) ? (string) $value : $value;

        if (
            ! is_string($text)
            || preg_match('/^[1-9][0-9]*$/D', $text) !== 1
        ) {
            throw new InvalidArgumentException(
                "El parametro {$field} debe ser un entero positivo."
            );
        }

        $number = filter_var($text, FILTER_VALIDATE_INT);

        if ($number === false) {
            throw new InvalidArgumentException(
                "El parametro {$field} esta fuera de rango."
            );
        }

        return $number;
    }

    private function perPage(): int
    {
        $value = $this->positiveInteger('per_page', 20);

        if (! in_array($value, [20, 50, 100], true)) {
            throw new InvalidArgumentException(
                'El parametro per_page debe ser 20, 50 o 100.'
            );
        }

        return $value;
    }

    private function enum(
        string $field,
        array $allowed,
        ?string $default = null,
        bool $uppercase = false
    ): ?string {
        if (! array_key_exists($field, $this->input)) {
            return $default;
        }

        $value = $this->value($field);

        if (! is_string($value)) {
            throw new InvalidArgumentException(
                "El parametro {$field} debe ser texto."
            );
        }

        $value = trim($value);
        $value = $uppercase ? strtoupper($value) : strtolower($value);

        if (! in_array($value, $allowed, true)) {
            throw new InvalidArgumentException(
                "El parametro {$field} no es valido."
            );
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private function assertCompatible(array $data): void
    {
        $cause = $data['cause'];
        $availability = $data['availability'];

        if ($cause === null || $availability === null) {
            return;
        }

        $diagnostic = [
            'product_reference_invalid',
            'store_reference_invalid',
            'product_missing',
            'store_missing',
            'reference_mismatch',
            'inventory_status_unknown',
            'product_status_unknown',
            'store_status_unknown',
        ];

        $expected = $cause === 'publicly_available'
            ? 'public'
            : (in_array($cause, $diagnostic, true)
                ? 'diagnostic_error'
                : 'not_public');

        if ($availability !== $expected) {
            throw new InvalidArgumentException(
                'Los parametros availability y cause son incompatibles.'
            );
        }
    }

    private function value(string $field): mixed
    {
        $value = $this->input[$field] ?? null;

        return is_string($value) ? wp_unslash($value) : $value;
    }
}
