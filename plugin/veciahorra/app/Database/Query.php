<?php

declare(strict_types=1);

namespace VeciAhorra\Database;

final class Query
{
    private \wpdb $db;

    private string $table;

    private array $select = ['*'];

    private array $where = [];

    private array $orderBy = [];

    private ?int $limit = null;

    private ?int $offset = null;

    public function __construct(
        \wpdb $db,
        string $table
    ) {
        $this->db = $db;
        $this->table = $table;
    }

    public function where(
        string $column,
        string $operator,
        mixed $value
    ): self {

        $this->where[] = [
            'column'   => $column,
            'operator' => $operator,
            'value'    => $value,
        ];

        return $this;
    }

    public function orderBy(
        string $column,
        string $direction = 'ASC'
    ): self {

        $this->orderBy = [
            $column,
            strtoupper($direction),
        ];

        return $this;
    }

    public function limit(
        int $rows
    ): self {

        $this->limit = $rows;

        return $this;
    }

    public function offset(
        int $rows
    ): self {

        $this->offset = $rows;

        return $this;
    }
}