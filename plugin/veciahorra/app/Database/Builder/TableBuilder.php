<?php

declare(strict_types=1);

namespace VeciAhorra\Database\Builder;

/**
 * Construye una tabla utilizando Blueprint
 * y genera su SQL mediante SqlGenerator.
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

    /**
     * Reenvía automáticamente los métodos al Blueprint.
     */
    public function __call(string $method, array $arguments): self
    {
        if (!method_exists($this->blueprint, $method)) {
            throw new \BadMethodCallException(
                "Method {$method} does not exist in Blueprint."
            );
        }

        $this->blueprint->$method(...$arguments);

        return $this;
    }

    /**
     * Genera el SQL.
     */
    public function build(
    string $charsetCollate = ''
    ): string

    return $generator->createTable(
        $this->tableName,
        $this->blueprint,
        $charsetCollate
    );
    
}