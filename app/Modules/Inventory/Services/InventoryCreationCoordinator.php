<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Inventory\Services;

use Throwable;
use VeciAhorra\Database\Repository;
use VeciAhorra\Exceptions\PersistenceException;
use VeciAhorra\Modules\Inventory\Exceptions\InventoryValidationException;

final class InventoryCreationCoordinator extends Repository
{
    public function execute(
        int $productId,
        int $storeId,
        callable $callback
    ): mixed {
        return $this->transaction(function () use (
            $productId,
            $storeId,
            $callback
        ): mixed {
            $this->lockRow('products', $productId);
            $this->lockRow('stores', $storeId);
            $this->lockInventoryRange($productId, $storeId);

            return $callback();
        });
    }

    public function assertProductExists(int $productId): void
    {
        $exists = $this->db()->get_var(
            $this->db()->prepare(
                sprintf(
                    'SELECT id FROM %s WHERE id = %%d FOR UPDATE',
                    $this->table('products')
                ),
                $productId
            )
        );

        if ($this->db()->last_error !== '') {
            throw new PersistenceException(
                'No fue posible revalidar el producto de Inventory.'
            );
        }

        if ($exists === null) {
            throw new InventoryValidationException(
                'El producto seleccionado no existe.',
                'product_id',
                'inventory_product_not_found'
            );
        }
    }

    private function lockRow(string $table, int $id): void
    {
        $this->db()->get_var(
            $this->db()->prepare(
                sprintf(
                    'SELECT id FROM %s WHERE id = %%d FOR UPDATE',
                    $this->table($table)
                ),
                $id
            )
        );

        if ($this->db()->last_error !== '') {
            throw new PersistenceException(
                'No fue posible bloquear una referencia de Inventory.'
            );
        }
    }

    private function lockInventoryRange(
        int $productId,
        int $storeId
    ): void {
        $this->db()->get_col(
            $this->db()->prepare(
                sprintf(
                    'SELECT id FROM %s'
                    . ' WHERE product_id = %%d AND minimarket_id = %%d'
                    . ' FOR UPDATE',
                    $this->table('inventory')
                ),
                $productId,
                $storeId
            )
        );

        if ($this->db()->last_error !== '') {
            throw new PersistenceException(
                'No fue posible bloquear el rango de Inventory.'
            );
        }
    }

    private function transaction(callable $callback): mixed
    {
        $nested = (int) $this->db()->get_var(
            'SELECT @@in_transaction'
        ) === 1;
        $savepoint = 'va_inventory_create_' . substr(hash(
            'sha256',
            (string) microtime(true) . random_int(1, PHP_INT_MAX)
        ), 0, 12);

        if ($nested) {
            if ($this->db()->query("SAVEPOINT {$savepoint}") === false) {
                throw new PersistenceException(
                    'No fue posible crear el savepoint de Inventory.'
                );
            }
        } elseif ($this->db()->query('START TRANSACTION') === false) {
            throw new PersistenceException(
                'No fue posible iniciar la transaccion de Inventory.'
            );
        }

        try {
            $result = $callback();
            $statement = $nested
                ? "RELEASE SAVEPOINT {$savepoint}"
                : 'COMMIT';

            if ($this->db()->query($statement) === false) {
                throw new PersistenceException(
                    'No fue posible confirmar la transaccion de Inventory.'
                );
            }

            return $result;
        } catch (Throwable $exception) {
            $this->db()->query(
                $nested
                    ? "ROLLBACK TO SAVEPOINT {$savepoint}"
                    : 'ROLLBACK'
            );
            throw $exception;
        }
    }
}
