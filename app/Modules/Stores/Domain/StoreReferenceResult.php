<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Stores\Domain;

use InvalidArgumentException;

final class StoreReferenceResult
{
    public function __construct(private array $counts)
    {
        foreach ($counts as $domain => $count) {
            if (! is_string($domain) || $domain === '' || ! is_int($count) || $count < 0) {
                throw new InvalidArgumentException('El resumen de referencias Store no es valido.');
            }
        }
        $this->counts = array_filter(
            $counts,
            static fn (int $count): bool => $count > 0
        );
    }

    public function isDeletable(): bool
    {
        return $this->counts === [];
    }

    public function domains(): array
    {
        return array_keys($this->counts);
    }

    public function counts(): array
    {
        return $this->counts;
    }

    public function reasons(): array
    {
        return array_map(
            static fn (string $domain): string => 'referenced_by_' . $domain,
            $this->domains()
        );
    }
}
