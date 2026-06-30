<?php

declare(strict_types=1);

namespace VeciAhorra\Database\Builder;

use InvalidArgumentException;

/**
 * Representa un índice de tabla.
 */
final class Index
{
    /**
     * @param string[] $columns
     */
    public function __construct(
        private string $name,
        private array $columns,
        private bool $unique = false
    ) {
        if ($this->name === '') {
            throw new InvalidArgumentException(
                'El nombre del índice no puede estar vacío.'
            );
        }

        if ($this->columns === []) {
            throw new InvalidArgumentException(
                'El índice debe contener al menos una columna.'
            );
        }
    }

    /**
     * Genera la definición SQL del índice.
     */
    public function toSql(): string
    {
        $type = $this->unique ? 'UNIQUE KEY' : 'KEY';

        return sprintf(
            '%s %s (%s)',
            $type,
            $this->name,
            implode(', ', $this->columns)
        );
    }
}
