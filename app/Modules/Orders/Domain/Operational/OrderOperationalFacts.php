<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Domain\Operational;

use InvalidArgumentException;

final readonly class OrderOperationalFacts
{
    private array $facts;

    public function __construct(array $facts, public string $observedAt)
    {
        if (! isset($facts['order']) || ! is_array($facts['order'])) {
            throw new InvalidArgumentException('OrderOperationalFacts requiere un snapshot de Order.');
        }
        if (! self::validTimestamp($observedAt)) {
            throw new InvalidArgumentException('El timestamp de evaluacion no es valido.');
        }

        $this->facts = self::copy($facts);
    }

    public function all(): array
    {
        return self::copy($this->facts);
    }

    private static function copy(array $value): array
    {
        return array_map(
            static fn (mixed $item): mixed => is_array($item) ? self::copy($item) : $item,
            $value
        );
    }

    private static function validTimestamp(string $value): bool
    {
        try {
            new \DateTimeImmutable($value);
            return true;
        } catch (\Exception) {
            return false;
        }
    }
}
