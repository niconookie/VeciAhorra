<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Delivery\Service;

use InvalidArgumentException;
use RuntimeException;
use VeciAhorra\Exceptions\RecordNotFoundException;
use VeciAhorra\Modules\Delivery\Models\Delivery;
use VeciAhorra\Modules\Delivery\Repository\DeliveryRepository;
use VeciAhorra\Modules\Orders\Repositories\OrderRepository;

/**
 * Casos de uso base del modulo Delivery.
 */
final class DeliveryService
{
    public function __construct(
        private DeliveryRepository $repository,
        private OrderRepository $orderRepository
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function createDelivery(array $payload): array
    {
        $orderId = (int) ($payload['order_id'] ?? 0);

        if ($orderId <= 0) {
            throw new InvalidArgumentException(
                'El pedido de la entrega es obligatorio.'
            );
        }

        $order = $this->orderRepository->find($orderId);

        if ($order === null) {
            throw new RecordNotFoundException(
                'El pedido solicitado no existe.'
            );
        }

        if (($order['status'] ?? null) !== 'paid') {
            throw new InvalidArgumentException(
                'Order must be paid before delivery creation.'
            );
        }

        $now = current_time('mysql');
        $deliveryId = $this->repository->create([
            'order_id' => $orderId,
            'customer_id' => (int) $order['customer_id'],
            'minimarket_id' => (int) $order['minimarket_id'],
            'status' => Delivery::STATUS_PENDING,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->repository->find($deliveryId)
            ?? throw new RuntimeException(
                'No fue posible recuperar la entrega creada.'
            );
    }

    public function getDelivery(int $id): ?array
    {
        return $this->repository->find($id);
    }

    /**
     * @return array{
     *     data: list<array<string, mixed>>,
     *     pagination: array{page: int, per_page: int, total: int, total_pages: int}
     * }
     */
    public function listDeliveries(array $filters = []): array
    {
        return $this->repository->paginate($filters);
    }
}
