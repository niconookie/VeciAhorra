<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Inventory\Repositories;

use VeciAhorra\Database\Repository;
use VeciAhorra\Exceptions\PersistenceException;

/**
 * Persistencia atomica de bloqueos de stock.
 */
final class InventoryLockRepository extends Repository
{
    private const TABLE = 'inventory';

    public function hasAvailableStock(
        int $inventoryId,
        int $quantity
    ): bool {
        $sql = sprintf(
            'SELECT COUNT(*)
             FROM %s
             WHERE id = %%d
               AND stock >= %%d',
            $this->table(self::TABLE)
        );

        return (int) $this->db()->get_var(
            $this->db()->prepare($sql, $inventoryId, $quantity)
        ) === 1;
    }

    public function decrementStock(
        int $inventoryId,
        int $quantity
    ): bool {
        $sql = sprintf(
            'UPDATE %s
             SET stock = stock - %%d,
                 updated_at = %%s
             WHERE id = %%d
               AND stock >= %%d',
            $this->table(self::TABLE)
        );
        $result = $this->db()->query(
            $this->db()->prepare(
                $sql,
                $quantity,
                current_time('mysql'),
                $inventoryId,
                $quantity
            )
        );

        if ($result === false) {
            throw new PersistenceException(
                'No fue posible bloquear el stock.'
            );
        }

        return $result === 1;
    }

    public function incrementStock(
        int $inventoryId,
        int $quantity
    ): bool {
        $sql = sprintf(
            'UPDATE %s
             SET stock = stock + %%d,
                 updated_at = %%s
             WHERE id = %%d',
            $this->table(self::TABLE)
        );
        $result = $this->db()->query(
            $this->db()->prepare(
                $sql,
                $quantity,
                current_time('mysql'),
                $inventoryId
            )
        );

        if ($result === false) {
            throw new PersistenceException(
                'No fue posible liberar el stock.'
            );
        }

        return $result === 1;
    }

    public function exists(int $inventoryId): bool
    {
        $sql = sprintf(
            'SELECT COUNT(*) FROM %s WHERE id = %%d',
            $this->table(self::TABLE)
        );

        return (int) $this->db()->get_var(
            $this->db()->prepare($sql, $inventoryId)
        ) === 1;
    }
}
