<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Reservations\Service;

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use VeciAhorra\Exceptions\PersistenceException;
use VeciAhorra\Modules\Inventory\Services\InventoryLockService;
use VeciAhorra\Modules\Reservations\Repository\ReservationRepository;

class ReservationService
{
    public const DURATION_MINUTES = 15;
    public const ALLOWED_STATUSES = ['active', 'released', 'expired', 'consumed'];

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

    public function find(int $id): ?array
    {
        return $this->repository->find($id);
    }

    /** @return list<array<string, mixed>> */
    public function findByOrderId(int $orderId): array
    {
        $this->assertPositive($orderId, 'order_id');

        return $this->repository->findByOrderId($orderId);
    }

    /**
     * Valida y bloquea todos los items. Libera los previos si uno falla.
     *
     * @return list<array<string, mixed>>
     */
    public function lockItems(array $items): array
    {
        $locked = [];

        try {
            foreach ($items as $item) {
                $inventoryId = $item['inventory_id'] ?? null;
                $quantity = $item['quantity'] ?? null;
                $this->assertPositive($inventoryId, 'inventory_id');
                $this->assertPositive($quantity, 'quantity');

                if (! $this->inventoryLockService->checkAvailability(
                    $inventoryId,
                    $quantity
                ) || ! $this->inventoryLockService->lockStock(
                    $inventoryId,
                    $quantity
                )) {
                    throw new InvalidArgumentException(
                        'El inventario solicitado no tiene stock suficiente.'
                    );
                }

                $locked[] = $item;
            }
        } catch (Throwable $exception) {
            $this->releaseItems($locked);

            throw $exception;
        }

        return $locked;
    }

    /** @param list<array<string, mixed>> $items */
    public function releaseItems(array $items): void
    {
        foreach (array_reverse($items) as $item) {
            $this->inventoryLockService->releaseStock(
                (int) $item['inventory_id'],
                (int) $item['quantity']
            );
        }
    }

    /**
     * Persiste una reserva por item que ya tiene stock bloqueado.
     *
     * @return list<array<string, mixed>>
     */
    public function createForOrder(
        int $orderId,
        int $minimarketId,
        array $items
    ): array {
        $this->assertPositive($orderId, 'order_id');
        $this->assertPositive($minimarketId, 'minimarket_id');
        $created = [];

        try {
            foreach ($items as $item) {
                $created[] = $this->persist([
                    'order_id' => $orderId,
                    'inventory_id' => $item['inventory_id'] ?? null,
                    'product_id' => $item['product_id'] ?? null,
                    'minimarket_id' => $minimarketId,
                    'quantity' => $item['quantity'] ?? null,
                ]);
            }
        } catch (Throwable $exception) {
            $this->cancelOrder($orderId, $items);

            throw $exception;
        }

        return $created;
    }

    /**
     * Bloquea y crea reservas pre-order para todos los items del checkout.
     *
     * @return list<array<string, mixed>>
     */
    public function createForCheckout(array $items): array
    {
        $locked = $this->lockItems($items);
        $created = [];

        try {
            foreach ($locked as $item) {
                $created[] = $this->persist([
                    'order_id' => null,
                    'inventory_id' => $item['inventory_id'] ?? null,
                    'product_id' => $item['product_id'] ?? null,
                    'minimarket_id' => $item['minimarket_id'] ?? null,
                    'quantity' => $item['quantity'] ?? null,
                ]);
            }
        } catch (Throwable $exception) {
            $this->releaseItems($locked);
            $this->repository->deleteByIds(array_map(
                static fn (array $reservation): int =>
                    (int) $reservation['id'],
                $created
            ));

            throw $exception;
        }

        return $created;
    }

    /** @param list<int> $reservationIds */
    public function assignToOrder(
        int $orderId,
        array $reservationIds
    ): void {
        $this->assertPositive($orderId, 'order_id');
        $this->repository->assignOrder(
            $reservationIds,
            $orderId,
            current_time('mysql')
        );
    }

    /** @param list<int> $orderIds */
    public function confirmForOrders(array $orderIds): int
    {
        if ($orderIds === []) {
            throw new InvalidArgumentException(
                'La confirmacion requiere pedidos.'
            );
        }

        foreach ($orderIds as $orderId) {
            $this->assertPositive($orderId, 'order_id');
        }

        $reservations = $this->repository->findByOrderIds($orderIds);
        $reservedOrderIds = array_values(array_unique(array_map(
            static fn (array $reservation): int =>
                (int) $reservation['order_id'],
            $reservations
        )));
        sort($reservedOrderIds);
        $expectedOrderIds = $orderIds;
        sort($expectedOrderIds);

        if ($reservations === [] || $reservedOrderIds !== $expectedOrderIds) {
            throw new InvalidArgumentException(
                'Los pedidos no tienen reservas confirmables.'
            );
        }

        foreach ($reservations as $reservation) {
            if (($reservation['status'] ?? null) !== 'active') {
                throw new InvalidArgumentException(
                    'Todas las reservas deben estar activas.'
                );
            }

            if (! $this->inventoryLockService->commitStock(
                (int) $reservation['inventory_id'],
                (int) $reservation['quantity']
            )) {
                throw new RuntimeException(
                    'No fue posible confirmar el stock reservado.'
                );
            }
        }

        return $this->repository->markConsumed(
            array_map(
                static fn (array $reservation): int =>
                    (int) $reservation['id'],
                $reservations
            ),
            current_time('mysql')
        );
    }

    /**
     * @param list<array<string, mixed>> $reservations
     * @param list<array<string, mixed>> $items
     */
    public function cancelCheckout(
        array $reservations,
        array $items
    ): void {
        $this->releaseItems($items);
        $this->repository->deleteByIds(array_map(
            static fn (array $reservation): int =>
                (int) $reservation['id'],
            $reservations
        ));
    }

    /** @param list<array<string, mixed>> $items */
    public function cancelOrder(int $orderId, array $items): void
    {
        $this->releaseItems($items);
        $this->repository->deleteByOrderId($orderId);
    }

    /** @return array<string, mixed> */
    public function create(array $data): array
    {
        $locked = $this->lockItems([$data]);

        try {
            return $this->persist($data);
        } catch (Throwable $exception) {
            $this->releaseItems($locked);

            if (is_int($data['order_id'] ?? null)) {
                $this->repository->deleteByOrderId($data['order_id']);
            }

            throw $exception;
        }
    }

    public function assertAllowedStatus(mixed $status): void
    {
        if (! is_string($status) || ! in_array($status, self::ALLOWED_STATUSES, true)) {
            throw new InvalidArgumentException('El estado de la reserva no es valido.');
        }
    }

    /** @return array<string, mixed> */
    private function persist(array $data): array
    {
        foreach (['inventory_id', 'product_id', 'minimarket_id', 'quantity'] as $field) {
            $this->assertPositive($data[$field] ?? null, $field);
        }

        $orderId = $data['order_id'] ?? null;

        if ($orderId !== null) {
            $this->assertPositive($orderId, 'order_id');
        }

        $status = $data['status'] ?? 'active';
        $this->assertAllowedStatus($status);
        $reservedAt = current_datetime();
        $reservedAtSql = $reservedAt->format('Y-m-d H:i:s');
        $payload = [
            'order_id' => $orderId === null ? null : (int) $orderId,
            'inventory_id' => (int) $data['inventory_id'],
            'product_id' => (int) $data['product_id'],
            'minimarket_id' => (int) $data['minimarket_id'],
            'quantity' => (int) $data['quantity'],
            'status' => $status,
            'reserved_at' => $reservedAtSql,
            'expires_at' => $reservedAt
                ->modify('+' . self::DURATION_MINUTES . ' minutes')
                ->format('Y-m-d H:i:s'),
            'released_at' => null,
            'created_at' => $reservedAtSql,
            'updated_at' => $reservedAtSql,
        ];

        try {
            $id = $this->repository->create($payload);
        } catch (PersistenceException $exception) {
            throw new RuntimeException(
                'No fue posible crear la reserva.',
                0,
                $exception
            );
        }

        $reservation = $this->repository->find($id);

        if ($reservation === null) {
            throw new RuntimeException(
                'No fue posible recuperar la reserva creada.'
            );
        }

        return $reservation;
    }

    private function assertPositive(mixed $value, string $field): void
    {
        if (! is_int($value) || $value <= 0) {
            throw new InvalidArgumentException(
                "El campo {$field} debe ser un entero positivo."
            );
        }
    }
}
