<?php

declare(strict_types=1);

namespace VeciAhorra\Database\Builder;

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
        $this->default = $value;

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

        if (!$this->nullable) {
            $sql .= " NOT NULL";
        }

        if ($this->default !== null) {
            $sql .= " DEFAULT '{$this->default}'";
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