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
     * @var Index[]
     */
    private array $indexes = [];

    /**
 * Última columna agregada.
 */
private ?Column $lastColumn = null;

    /**
     * Agrega una columna personalizada.
     */
   public function add(Column $column): self
{
    $this->columns[] = $column;

    $this->lastColumn = $column;

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
* Columna TEXT.
*/
public function text(string $name): self
{
    return $this->add(
        new Column(
            $name,
            'TEXT'
        )
    );
}

/**
* Columna INTEGER.
*/
public function integer(string $name): self
{
    return $this->add(
        new Column(
            $name,
            'INT'
        )
    );
}

/**
 * Columna INTEGER UNSIGNED.
 */
public function integerUnsigned(string $name): self
{
    return $this->add(
        new Column(
            $name,
            'INT UNSIGNED'
        )
    );
}

/**
 * Columna BIGINT UNSIGNED.
 */
public function bigIntegerUnsigned(string $name): self
{
    return $this->add(
        new Column(
            $name,
            'BIGINT UNSIGNED'
        )
    );
}

/**
* Columna DECIMAL.
*/
public function decimal(
    string $name,
    int $precision = 10,
    int $scale = 2
): self {

    return $this->add(
        new Column(
            $name,
            "DECIMAL($precision,$scale)"
        )
    );
}

/**
* Columna DATETIME.
*/
public function datetime(string $name): self
{
    return $this->add(
        new Column(
            $name,
            'DATETIME'
        )
    );
}

/**
* Columna TIME.
*/
public function time(string $name): self
{
    return $this->add(
        new Column(
            $name,
            'TIME'
        )
    );
}

/**
* Columna BOOLEAN.
*/
public function boolean(string $name): self
{
    return $this->add(
        new Column(
            $name,
            'TINYINT(1)'
        )
    );
}

/**
 * Marca la última columna como NULL.
 */
public function nullable(): self
{
    if ($this->lastColumn !== null) {
        $this->lastColumn->nullable();
    }

    return $this;
}

/**
 * Define un valor por defecto para la última columna.
 */
public function default(string $value): self
{
    if ($this->lastColumn !== null) {
        $this->lastColumn->default($value);
    }

    return $this;
}

/**
 * Agrega un índice normal.
 *
 * @param string[] $columns
 */
public function index(string $name, array $columns): self
{
    $this->indexes[] = new Index($name, $columns);

    return $this;
}

/**
 * Agrega un índice único.
 *
 * @param string[] $columns
 */
public function unique(string $name, array $columns): self
{
    $this->indexes[] = new Index($name, $columns, true);

    return $this;
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

    /**
     * Retorna todos los índices.
     *
     * @return Index[]
     */
    public function indexes(): array
    {
        return $this->indexes;
    }
}
