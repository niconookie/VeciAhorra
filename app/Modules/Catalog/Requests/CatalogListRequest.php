<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Catalog\Requests;

use InvalidArgumentException;

final class CatalogListRequest
{
    private const ORDERS = ['name', 'price', 'newest'];
    private const MAXIMUM_PAGE = 1000000;
    private const MAXIMUM_ID = 999999999999999999;

    public function __construct(private array $input)
    {
    }

    /** @return array{category: ?int, brand: ?int, search: ?string, page: int, per_page: int, order_by: string} */
    public function validated(): array
    {
        return [
            'category' => $this->optionalId('category'),
            'brand' => $this->optionalId('brand'),
            'search' => $this->search(),
            'page' => $this->integer('page', 1, 1, self::MAXIMUM_PAGE),
            'per_page' => $this->integer('per_page', 20, 1, 100),
            'order_by' => $this->orderBy(),
        ];
    }

    private function optionalId(string $key): ?int
    {
        if (! array_key_exists($key, $this->input) || $this->input[$key] === '') {
            return null;
        }

        return $this->integer($key, 0, 1, self::MAXIMUM_ID);
    }

    private function search(): ?string
    {
        $value = $this->input['search'] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException('Search must be a string.');
        }

        $value = sanitize_text_field(trim($value));

        if ($value === '') {
            return null;
        }

        if (strlen($value) > 100) {
            throw new InvalidArgumentException('Search is too long.');
        }

        return $value;
    }

    private function orderBy(): string
    {
        $value = $this->input['order_by'] ?? 'name';

        if (! is_string($value) || ! in_array($value, self::ORDERS, true)) {
            throw new InvalidArgumentException('Invalid catalog ordering.');
        }

        return $value;
    }

    private function integer(
        string $key,
        int $default,
        int $minimum,
        int $maximum
    ): int {
        $value = $this->input[$key] ?? $default;

        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }

        if (! is_int($value) || $value < $minimum || $value > $maximum) {
            throw new InvalidArgumentException("Invalid {$key} value.");
        }

        return $value;
    }
}
