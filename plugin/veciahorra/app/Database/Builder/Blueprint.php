<?php

declare(strict_types=1);

namespace VeciAhorra\Database\Builder;

/**
 * Builder de tablas.
 *
 * Almacena las columnas que posteriormente
 * serán convertidas en SQL por TableBuilder.
 */
final class Blueprint
{
    /**
     * @var Column[]
     */
    private array $columns = [];

    /**
     * Agrega una columna personalizada.
     */
    public function add(Column $column): self
    {
        $this->columns[] = $column;

        return $this;
    }

    /**
     * Columna ID.
     */
    public function id(): self
    {
        return $this->add(
            (new Column(
                'id',
                'BIGINT UNSIGNED'
            ))
            ->autoIncrement()
            ->primary()
        );
    }

    /**
     * Columna VARCHAR.
     */
    public function string(
        string $name,
        int $length = 255
    ): self {

        return $this->add(

            new Column(
                $name,
                "VARCHAR($length)"
            )

        );
    }

    /**
     * Retorna todas las columnas.
     *
     * @return Column[]
     */
    public function columns(): array
    {
        return $this->columns;
    }
}