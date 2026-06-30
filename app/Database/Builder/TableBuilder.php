<?php

declare(strict_types=1);

namespace VeciAhorra\Database\Builder;

/**
 * Construye tablas utilizando Blueprint
 * y genera el SQL mediante SqlGenerator.
 */
final class TableBuilder
{
    private string $tableName;

    private Blueprint $blueprint;

    public function __construct(string $tableName)
    {
        $this->tableName = $tableName;
        $this->blueprint = new Blueprint();
    }

    public static function make(string $tableName): self
    {
        return new self($tableName);
    }

    /*
    |--------------------------------------------------------------------------
    | Tipos de columnas
    |--------------------------------------------------------------------------
    */

    public function id(): self
    {
        $this->blueprint->id();

        return $this;
    }

    public function string(
        string $name,
        int $length = 255
    ): self {

        $this->blueprint->string($name, $length);

        return $this;
    }

    public function text(string $name): self
    {
        $this->blueprint->text($name);

        return $this;
    }

    public function integer(string $name): self
    {
        $this->blueprint->integer($name);

        return $this;
    }

    public function bigIntegerUnsigned(string $name): self
    {
        $this->blueprint->bigIntegerUnsigned($name);

        return $this;
    }

    public function decimal(
        string $name,
        int $precision = 10,
        int $scale = 2
    ): self {

        $this->blueprint->decimal(
            $name,
            $precision,
            $scale
        );

        return $this;
    }

    public function datetime(string $name): self
    {
        $this->blueprint->datetime($name);

        return $this;
    }

    public function time(string $name): self
    {
        $this->blueprint->time($name);

        return $this;
    }

    public function boolean(string $name): self
    {
        $this->blueprint->boolean($name);

        return $this;
    }

    /*
    |--------------------------------------------------------------------------
    | Modificadores
    |--------------------------------------------------------------------------
    */

    public function nullable(): self
    {
        $this->blueprint->nullable();

        return $this;
    }

    public function default(string $value): self
    {
        $this->blueprint->default($value);

        return $this;
    }

    /**
     * @param string|string[] $columns
     */
    public function index(string|array $columns, string $name): self
    {
        $this->blueprint->index($name, (array) $columns);

        return $this;
    }

    /**
     * @param string|string[] $columns
     */
    public function unique(string|array $columns, string $name): self
    {
        $this->blueprint->unique($name, (array) $columns);

        return $this;
    }

    /*
    |--------------------------------------------------------------------------
    | SQL
    |--------------------------------------------------------------------------
    */

    public function build(
        string $charsetCollate = ''
    ): string {

        $generator = new SqlGenerator();

        return $generator->createTable(
            $this->tableName,
            $this->blueprint,
            $charsetCollate
        );
    }
}
