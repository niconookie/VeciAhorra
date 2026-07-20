<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Stores\Requests;

use VeciAhorra\Modules\Stores\Exceptions\StoreListValidationException;

/**
 * Valida el listado REST administrativo de minimarkets.
 */
final class StoreListRequest
{
    private const DEFAULT_PAGE = 1;

    private const MAXIMUM_PAGE = 1000000;

    private const DEFAULT_PER_PAGE = 20;

    private const MAXIMUM_PER_PAGE = 100;

    private const MAXIMUM_SEARCH_LENGTH = 100;

    private const DEFAULT_ORDER_BY = 'business_name';

    private const DEFAULT_DIRECTION = 'ASC';

    private const ALLOWED_STATUSES = [
        'pending',
        'active',
        'inactive',
        'rejected',
    ];

    private const ALLOWED_ORDER_FIELDS = [
        'business_name',
        'id',
        'status',
    ];

    public function __construct(private array $input)
    {
    }

    /**
     * @return array{
     *   page: int,
     *   per_page: int,
     *   search: string|null,
     *   status: string|null,
     *   order_by: string,
     *   direction: string
     * }
     */
    public function validated(): array
    {
        return [
            'page' => $this->integer(
                'page',
                self::DEFAULT_PAGE,
                1,
                self::MAXIMUM_PAGE
            ),
            'per_page' => $this->integer(
                'per_page',
                self::DEFAULT_PER_PAGE,
                1,
                self::MAXIMUM_PER_PAGE
            ),
            'search' => $this->search(),
            'status' => $this->optionalEnum(
                'status',
                self::ALLOWED_STATUSES
            ),
            'order_by' => $this->enum(
                'order_by',
                self::ALLOWED_ORDER_FIELDS,
                self::DEFAULT_ORDER_BY
            ),
            'direction' => $this->direction(),
        ];
    }

    private function search(): ?string
    {
        if (! array_key_exists('search', $this->input)) {
            return null;
        }

        $value = $this->input['search'];

        if (! is_string($value)) {
            throw new StoreListValidationException(
                'search',
                'El parametro search debe ser texto.'
            );
        }

        $search = trim(sanitize_text_field(wp_unslash($value)));

        if ($search === '') {
            return null;
        }

        $length = function_exists('mb_strlen')
            ? mb_strlen($search)
            : strlen($search);

        if ($length > self::MAXIMUM_SEARCH_LENGTH) {
            throw new StoreListValidationException(
                'search',
                'El parametro search no puede superar 100 caracteres.'
            );
        }

        return $search;
    }

    /** @param list<string> $allowed */
    private function optionalEnum(string $key, array $allowed): ?string
    {
        if (! array_key_exists($key, $this->input)) {
            return null;
        }

        $value = $this->input[$key];

        if (! is_string($value)) {
            throw new StoreListValidationException(
                $key,
                "El parametro {$key} debe ser texto."
            );
        }

        $value = strtolower(trim(wp_unslash($value)));

        if ($value === '') {
            return null;
        }

        if (! in_array($value, $allowed, true)) {
            throw new StoreListValidationException(
                $key,
                "El parametro {$key} no es valido."
            );
        }

        return $value;
    }

    /** @param list<string> $allowed */
    private function enum(
        string $key,
        array $allowed,
        string $default
    ): string {
        if (! array_key_exists($key, $this->input)) {
            return $default;
        }

        $value = $this->input[$key];

        if (! is_string($value)) {
            throw new StoreListValidationException(
                $key,
                "El parametro {$key} debe ser texto."
            );
        }

        $value = strtolower(trim(wp_unslash($value)));

        if (! in_array($value, $allowed, true)) {
            throw new StoreListValidationException(
                $key,
                "El parametro {$key} no es valido."
            );
        }

        return $value;
    }

    private function direction(): string
    {
        if (! array_key_exists('direction', $this->input)) {
            return self::DEFAULT_DIRECTION;
        }

        $value = $this->input['direction'];

        if (! is_string($value)) {
            throw new StoreListValidationException(
                'direction',
                'El parametro direction debe ser texto.'
            );
        }

        $value = strtoupper(trim(wp_unslash($value)));

        if (! in_array($value, ['ASC', 'DESC'], true)) {
            throw new StoreListValidationException(
                'direction',
                'El parametro direction no es valido.'
            );
        }

        return $value;
    }

    private function integer(
        string $key,
        int $default,
        int $minimum,
        int $maximum
    ): int {
        if (! array_key_exists($key, $this->input)) {
            return $default;
        }

        $value = $this->input[$key];

        if (is_string($value)) {
            $value = trim(wp_unslash($value));

            if ($value === '' || ! ctype_digit($value)) {
                throw new StoreListValidationException(
                    $key,
                    "El parametro {$key} debe ser un entero."
                );
            }

            $normalized = ltrim($value, '0');
            $normalized = $normalized === '' ? '0' : $normalized;

            if (
                strlen($normalized) > strlen((string) PHP_INT_MAX)
                || (
                    strlen($normalized) === strlen((string) PHP_INT_MAX)
                    && strcmp($normalized, (string) PHP_INT_MAX) > 0
                )
            ) {
                throw new StoreListValidationException(
                    $key,
                    "El parametro {$key} esta fuera de rango."
                );
            }

            $value = (int) $normalized;
        }

        if (
            ! is_int($value)
            || $value < $minimum
            || $value > $maximum
        ) {
            throw new StoreListValidationException(
                $key,
                sprintf(
                    'El parametro %s debe estar entre %d y %d.',
                    $key,
                    $minimum,
                    $maximum
                )
            );
        }

        return $value;
    }
}
