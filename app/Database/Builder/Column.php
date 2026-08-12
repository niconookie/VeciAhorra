<?php

declare(strict_types=1);

namespace VeciAhorra\Database\Builder;

use InvalidArgumentException;

/**
 * VeciAhorra
 *
 * Representa una columna de una tabla.
 *
 * @package VeciAhorra
 * @since 1.0.0
 */
final class Column
{
    private string $name;

    private string $type;

    private bool $nullable = false;

    private ?string $default = null;

    private bool $defaultNull = false;

    private bool $autoIncrement = false;

    private bool $primary = false;

    public function __construct(string $name, string $type)
    {
        $this->name = $name;

        $this->type = $type;
    }

    public function nullable(): self
    {
        $this->nullable = true;

        return $this;
    }

    public function default(string $value): self
    {
        if ($this->defaultNull) {
            throw new InvalidArgumentException(
                'A column cannot combine a scalar default with DEFAULT NULL.'
            );
        }

        $this->default = $value;

        return $this;
    }

    public function defaultNull(): self
    {
        if (! $this->nullable) {
            throw new InvalidArgumentException(
                'DEFAULT NULL requires a nullable column.'
            );
        }
        if ($this->default !== null) {
            throw new InvalidArgumentException(
                'A column cannot combine DEFAULT NULL with a scalar default.'
            );
        }

        $this->defaultNull = true;

        return $this;
    }

    public function autoIncrement(): self
    {
        $this->autoIncrement = true;

        return $this;
    }

    public function primary(): self
    {
        $this->primary = true;

        return $this;
    }

    public function toSql(): string
{
    $sql = "{$this->name} {$this->type}";

    if ($this->nullable) {
        $sql .= " NULL";
    } else {
        $sql .= " NOT NULL";
    }

    if ($this->default !== null) {
        $sql .= " DEFAULT '{$this->default}'";
    } elseif ($this->defaultNull) {
        $sql .= ' DEFAULT NULL';
    }

    if ($this->autoIncrement) {
        $sql .= " AUTO_INCREMENT";
    }

    return $sql;
}

    public function isPrimary(): bool
    {
        return $this->primary;
    }
    /**
 * Nombre de la columna.
 */
public function getName(): string
{
    return $this->name;
}
}
