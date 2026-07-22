<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Products\Repositories;

use VeciAhorra\Database\Repository;
use VeciAhorra\Exceptions\PersistenceException;
use VeciAhorra\Modules\Products\Domain\ProductReferenceInspection;

final class ProductReferenceInspector extends Repository
{
    private const TABLES = [
        'inventory',
        'cart_items',
        'reservations',
        'order_items',
    ];

    public function inspect(
        int $productId,
        bool $forUpdate = false
    ): ProductReferenceInspection {
        if ($forUpdate) {
            foreach (self::TABLES as $table) {
                $this->lockProductRange($table, $productId);
            }
        }

        return new ProductReferenceInspection(
            $productId,
            $this->inventory($productId),
            $this->cart($productId),
            $this->reservations($productId),
            $this->orderItems($productId)
        );
    }

    private function lockProductRange(string $table, int $productId): void
    {
        $this->db()->get_col(
            $this->db()->prepare(
                sprintf(
                    'SELECT id FROM %s WHERE product_id = %%d FOR UPDATE',
                    $this->table($table)
                ),
                $productId
            )
        );

        if ($this->db()->last_error !== '') {
            throw new PersistenceException(
                'No fue posible bloquear las referencias del producto.'
            );
        }
    }

    private function inventory(int $productId): array
    {
        $row = $this->aggregate(
            'SELECT COUNT(*) AS total,'
            . " SUM(CASE WHEN i.status = 'active' THEN 1 ELSE 0 END) AS active,"
            . " SUM(CASE WHEN i.status = 'inactive' THEN 1 ELSE 0 END) AS inactive,"
            . " SUM(CASE WHEN i.status NOT IN ('active', 'inactive')"
            . ' OR s.id IS NULL THEN 1 ELSE 0 END) AS inconsistent'
            . ' FROM %s i LEFT JOIN %s s ON s.id = i.minimarket_id'
            . ' WHERE i.product_id = %%d',
            ['inventory', 'stores'],
            $productId
        );

        return $this->integers($row, [
            'total', 'active', 'inactive', 'inconsistent',
        ]);
    }

    private function cart(int $productId): array
    {
        $row = $this->aggregate(
            'SELECT COUNT(*) AS total,'
            . " SUM(CASE WHEN i.id IS NOT NULL AND i.product_id = c.product_id"
            . " AND i.status = 'active' THEN 1 ELSE 0 END) AS current_items,"
            . " SUM(CASE WHEN i.id IS NULL OR i.status <> 'active'"
            . ' THEN 1 ELSE 0 END) AS residual,'
            . ' SUM(CASE WHEN i.id IS NULL OR i.product_id <> c.product_id'
            . ' THEN 1 ELSE 0 END) AS inconsistent'
            . ' FROM %s c LEFT JOIN %s i ON i.id = c.inventory_id'
            . ' WHERE c.product_id = %%d',
            ['cart_items', 'inventory'],
            $productId
        );

        return $this->integers($row, [
            'total', 'current_items', 'residual', 'inconsistent',
        ]);
    }

    private function reservations(int $productId): array
    {
        $row = $this->aggregate(
            'SELECT COUNT(*) AS total,'
            . " SUM(CASE WHEN r.status = 'active' THEN 1 ELSE 0 END) AS active,"
            . " SUM(CASE WHEN r.status = 'released' THEN 1 ELSE 0 END) AS released,"
            . " SUM(CASE WHEN r.status = 'expired' THEN 1 ELSE 0 END) AS expired,"
            . " SUM(CASE WHEN r.status = 'consumed' THEN 1 ELSE 0 END) AS consumed,"
            . " SUM(CASE WHEN r.status NOT IN ('active','released','expired','consumed')"
            . ' OR i.id IS NULL OR i.product_id <> r.product_id'
            . ' THEN 1 ELSE 0 END) AS inconsistent'
            . ' FROM %s r LEFT JOIN %s i ON i.id = r.inventory_id'
            . ' WHERE r.product_id = %%d',
            ['reservations', 'inventory'],
            $productId
        );

        return $this->integers($row, [
            'total', 'active', 'released', 'expired', 'consumed',
            'inconsistent',
        ]);
    }

    private function orderItems(int $productId): array
    {
        $row = $this->aggregate(
            'SELECT COUNT(*) AS total,'
            . ' SUM(CASE WHEN i.id IS NULL OR i.product_id <> oi.product_id'
            . ' THEN 1 ELSE 0 END) AS inconsistent'
            . ' FROM %s oi LEFT JOIN %s i ON i.id = oi.inventory_id'
            . ' WHERE oi.product_id = %%d',
            ['order_items', 'inventory'],
            $productId
        );

        return $this->integers($row, ['total', 'inconsistent']);
    }

    private function aggregate(
        string $template,
        array $tables,
        int $productId
    ): array {
        $physicalTables = array_map(
            fn (string $table): string => $this->table($table),
            $tables
        );
        $sql = vsprintf($template, $physicalTables);
        $row = $this->db()->get_row(
            $this->db()->prepare($sql, $productId),
            ARRAY_A
        );

        if ($row === null || $this->db()->last_error !== '') {
            throw new PersistenceException(
                'No fue posible inspeccionar las referencias del producto.'
            );
        }

        return $row;
    }

    private function integers(array $row, array $fields): array
    {
        $result = [];

        foreach ($fields as $field) {
            $result[$field] = (int) ($row[$field] ?? 0);
        }

        return $result;
    }
}
