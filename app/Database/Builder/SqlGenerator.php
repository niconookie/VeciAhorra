<?php

declare(strict_types=1);

namespace VeciAhorra\Database\Builder;

/**
 * Convierte un Blueprint en SQL.
 */
final class SqlGenerator
{
    /**
     * Genera el SQL de creación de tabla.
     */
    public function createTable(
        string $tableName,
        Blueprint $blueprint,
        string $charsetCollate = ''
    ): string {

        $columns = [];
        $primary = [];

        foreach ($blueprint->columns() as $column) {

            $columns[] = $column->toSql();

            if ($column->isPrimary()) {
                $primary[] = $column->getName();
            }
        }

        if (! empty($primary)) {
            $columns[] = 'PRIMARY KEY (' . implode(', ', $primary) . ')';
        }

        return sprintf(
            "CREATE TABLE %s (\n%s\n) ENGINE=InnoDB %s;",
            $tableName,
            implode(",\n", $columns),
            $charsetCollate
        );
    }
}