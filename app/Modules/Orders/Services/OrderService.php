<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Services;

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use VeciAhorra\Exceptions\PersistenceException;
use VeciAhorra\Modules\Orders\Repositories\OrderRepository;
use VeciAhorra\Modules\Reservations\Service\ReservationService;

/**
 * Casos de uso iniciales para crear pedidos reservados.
 */
final class OrderService
{
    private OrderRepository $repository;

    private ReservationService $reservationService;

    public function __construct(
        ?OrderRepository $repository = null,
        ?ReservationService $reservationService = null
    ) {
        $this->repository = $repository
            ?? new OrderRepository();
        $this->reservationService = $reservationService
            ?? new ReservationService();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(array $filters = []): array
    {
        return $this->repository->list($filters);
    }

    public function find(int $id): ?array
    {
        return $this->repository->find($id);
    }

    /**
     * @return array<string, mixed>
     */
    public function create(array $payload): array
    {
        $items = $payload['items'] ?? null;

        if (! is_array($items) || $items === []) {
            throw new InvalidArgumentException(
                'El pedido debe contener al menos un item.'
            );
        }

        $createdAt = current_datetime();
        $createdAtSql = $createdAt->format('Y-m-d H:i:s');
        $reservationExpiresAt = $createdAt
            ->modify('+' . ReservationService::DURATION_MINUTES . ' minutes')
            ->format('Y-m-d H:i:s');
        $preparedItems = [];
        $total = 0.0;

        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                throw new InvalidArgumentException(sprintf(
                    'El item %d del pedido no es valido.',
                    $index
                ));
            }

            $quantity = $item['quantity'] ?? null;
            $unitPrice = $item['unit_price'] ?? null;
            $productId = $item['product_id'] ?? null;
            $inventoryId = $item['inventory_id'] ?? null;

            if (! is_int($quantity) || $quantity <= 0) {
                throw new InvalidArgumentException(sprintf(
                    'La cantidad del item %d debe ser un entero positivo.',
                    $index
                ));
            }

            if (
                (! is_int($unitPrice) && ! is_float($unitPrice))
                || ! is_finite((float) $unitPrice)
                || $unitPrice < 0
            ) {
                throw new InvalidArgumentException(sprintf(
                    'El precio unitario del item %d no es valido.',
                    $index
                ));
            }

            if (! is_int($productId) || $productId <= 0) {
                throw new InvalidArgumentException(sprintf(
                    'El producto del item %d debe ser un entero positivo.',
                    $index
                ));
            }

            if (! is_int($inventoryId) || $inventoryId <= 0) {
                throw new InvalidArgumentException(sprintf(
                    'El inventario del item %d debe ser un entero positivo.',
                    $index
                ));
            }

            $subtotal = round($quantity * (float) $unitPrice, 2);
            $total = round($total + $subtotal, 2);
            $preparedItems[] = [
                'product_id' => $productId,
                'inventory_id' => $inventoryId,
                'quantity' => $quantity,
                'unit_price' => round((float) $unitPrice, 2),
                'subtotal' => $subtotal,
                'created_at' => $createdAtSql,
                'updated_at' => $createdAtSql,
            ];
        }

        $order = [
            'customer_id' => (int) ($payload['customer_id'] ?? 0),
            'minimarket_id' => (int) ($payload['minimarket_id'] ?? 0),
            'total' => $total,
            'status' => 'reserved',
            'reservation_expires_at' => $reservationExpiresAt,
            'created_at' => $createdAtSql,
            'updated_at' => $createdAtSql,
        ];

        $lockedItems = $this->reservationService->lockItems($preparedItems);
        $orderId = null;

        try {
            $orderId = $this->repository->create($order);
            $this->repository->createItems($orderId, $preparedItems);
        } catch (PersistenceException $exception) {
            $this->cleanupFailedOrder($orderId, $lockedItems);

            throw new RuntimeException(
                'No fue posible crear el pedido.',
                0,
                $exception
            );
        }

        try {
            $reservations = $this->reservationService->createForOrder(
                $orderId,
                (int) $order['minimarket_id'],
                $lockedItems
            );
        } catch (Throwable $exception) {
            $this->repository->delete($orderId);

            throw $exception;
        }

        $created = $this->repository->find($orderId);

        if ($created === null) {
            $this->reservationService->cancelOrder(
                $orderId,
                $lockedItems
            );
            $this->repository->delete($orderId);

            throw new RuntimeException(
                'No fue posible recuperar el pedido creado.'
            );
        }

        return [
            ...$created,
            'items' => $preparedItems,
            'reservations' => $reservations,
        ];
    }

    /**
     * Persiste un pedido para items cuyo stock ya fue reservado.
     *
     * @return array<string, mixed>
     */
    public function createFromReservedItems(
        int $customerId,
        int $minimarketId,
        array $items,
        string $total,
        string $expiresAt
    ): array {
        if ($customerId <= 0 || $minimarketId <= 0 || $items === []) {
            throw new InvalidArgumentException(
                'El pedido reservado contiene datos invalidos.'
            );
        }

        $now = current_time('mysql');
        $preparedItems = array_map(
            static fn (array $item): array => [
                'product_id' => (int) $item['product_id'],
                'inventory_id' => (int) $item['inventory_id'],
                'quantity' => (int) $item['quantity'],
                'unit_price' => (string) $item['unit_price_snapshot'],
                'subtotal' => (string) $item['subtotal'],
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $items
        );
        $order = [
            'customer_id' => $customerId,
            'minimarket_id' => $minimarketId,
            'total' => $total,
            'status' => 'reserved',
            'reservation_expires_at' => $expiresAt,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $orderId = null;

        try {
            $orderId = $this->repository->create($order);
            $this->repository->createItems($orderId, $preparedItems);
        } catch (PersistenceException $exception) {
            if ($orderId !== null) {
                $this->repository->delete($orderId);
            }

            throw new RuntimeException(
                'No fue posible crear el pedido reservado.',
                0,
                $exception
            );
        }

        $created = $this->repository->find($orderId);

        if ($created === null) {
            $this->repository->delete($orderId);

            throw new RuntimeException(
                'No fue posible recuperar el pedido reservado.'
            );
        }

        return [...$created, 'items' => $preparedItems];
    }

    /** @param list<int> $orderIds */
    public function cancelOrders(array $orderIds): void
    {
        foreach (array_reverse($orderIds) as $orderId) {
            $this->repository->delete($orderId);
        }
    }

    /** @param list<int> $orderIds */
    public function markPaid(array $orderIds): int
    {
        if ($orderIds === []) {
            throw new InvalidArgumentException(
                'El pago debe contener al menos un pedido.'
            );
        }

        foreach ($orderIds as $orderId) {
            if (! is_int($orderId) || $orderId <= 0) {
                throw new InvalidArgumentException(
                    'Los identificadores de pedido deben ser positivos.'
                );
            }
        }

        return $this->repository->markPaid(
            $orderIds,
            current_time('mysql')
        );
    }

    /** @param list<array<string, mixed>> $lockedItems */
    private function cleanupFailedOrder(
        ?int $orderId,
        array $lockedItems
    ): void {
        $this->reservationService->releaseItems($lockedItems);

        if ($orderId !== null) {
            $this->repository->delete($orderId);
        }
    }
}
