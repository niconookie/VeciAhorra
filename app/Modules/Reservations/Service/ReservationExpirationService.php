<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Reservations\Service;

use RuntimeException;
use Throwable;
use VeciAhorra\Exceptions\PersistenceException;
use VeciAhorra\Modules\Inventory\Services\InventoryLockService;
use VeciAhorra\Modules\Reservations\Repository\ReservationRepository;

/**
 * Expira manualmente reservas vencidas y devuelve su stock.
 */
final class ReservationExpirationService
{
    private ReservationRepository $repository;

    private InventoryLockService $inventoryLockService;

    public function __construct(
        ?ReservationRepository $repository = null,
        ?InventoryLockService $inventoryLockService = null
    ) {
        $this->repository = $repository
            ?? new ReservationRepository();
        $this->inventoryLockService = $inventoryLockService
            ?? new InventoryLockService();
    }

    /**
     * @return int Cantidad de reservas expiradas en esta ejecucion.
     */
    public function processExpiredReservations(): int
    {
        $now = current_time('mysql');
        $reservations = $this->repository->findExpiredActive($now);
        $processed = 0;

        foreach ($reservations as $reservation) {
            $id = (int) ($reservation['id'] ?? 0);
            $inventoryId = (int) ($reservation['inventory_id'] ?? 0);
            $quantity = (int) ($reservation['quantity'] ?? 0);

            try {
                if (! $this->repository->markExpired($id, $now)) {
                    continue;
                }

                if (! $this->inventoryLockService->releaseStock(
                    $inventoryId,
                    $quantity
                )) {
                    throw new RuntimeException(
                        'No fue posible devolver el stock de la reserva.'
                    );
                }
            } catch (Throwable $exception) {
                $this->restoreAfterFailure($id, $now, $exception);
            }

            $processed++;
        }

        return $processed;
    }

    private function restoreAfterFailure(
        int $id,
        string $now,
        Throwable $cause
    ): never {
        try {
            $this->repository->restoreActive($id, $now);
        } catch (PersistenceException $restoreException) {
            throw new RuntimeException(
                'No fue posible restaurar la reserva tras el fallo.',
                0,
                $restoreException
            );
        }

        throw new RuntimeException(
            'No fue posible expirar la reserva.',
            0,
            $cause
        );
    }
}
