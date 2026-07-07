<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Inventory\Services;

use InvalidArgumentException;
use RuntimeException;
use VeciAhorra\Exceptions\PersistenceException;
use VeciAhorra\Modules\Inventory\Repositories\InventoryLockRepository;

/**
 * Reglas de negocio para bloquear, liberar y confirmar stock.
 */
final class InventoryLockService
{
    private InventoryLockRepository $repository;

    public function __construct(
        ?InventoryLockRepository $repository = null
    ) {
        $this->repository = $repository
            ?? new InventoryLockRepository();
    }

    public function checkAvailability(
        int $inventoryId,
        int $quantity
    ): bool {
        $this->validate($inventoryId, $quantity);

        return $this->repository->hasAvailableStock(
            $inventoryId,
            $quantity
        );
    }

    public function lockStock(
        int $inventoryId,
        int $quantity
    ): bool {
        $this->validate($inventoryId, $quantity);

        try {
            return $this->repository->decrementStock(
                $inventoryId,
                $quantity
            );
        } catch (PersistenceException $exception) {
            throw new RuntimeException(
                'No fue posible bloquear el stock.',
                0,
                $exception
            );
        }
    }

    public function releaseStock(
        int $inventoryId,
        int $quantity
    ): bool {
        $this->validate($inventoryId, $quantity);

        try {
            return $this->repository->incrementStock(
                $inventoryId,
                $quantity
            );
        } catch (PersistenceException $exception) {
            throw new RuntimeException(
                'No fue posible liberar el stock.',
                0,
                $exception
            );
        }
    }

    public function commitStock(
        int $inventoryId,
        int $quantity
    ): bool {
        $this->validate($inventoryId, $quantity);

        return $this->repository->exists($inventoryId);
    }

    private function validate(int $inventoryId, int $quantity): void
    {
        if ($inventoryId <= 0) {
            throw new InvalidArgumentException(
                'El identificador de inventario debe ser positivo.'
            );
        }

        if ($quantity <= 0) {
            throw new InvalidArgumentException(
                'La cantidad debe ser mayor que 0.'
            );
        }
    }
}
