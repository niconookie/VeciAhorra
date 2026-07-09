<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Delivery\Service;

use DomainException;
use InvalidArgumentException;
use RuntimeException;
use VeciAhorra\Exceptions\RecordNotFoundException;
use VeciAhorra\Modules\Couriers\Repository\CourierRepository;
use VeciAhorra\Modules\Delivery\Models\Delivery;
use VeciAhorra\Modules\Delivery\Repository\DeliveryRepository;
use VeciAhorra\Modules\Orders\Repositories\OrderRepository;

/**
 * Casos de uso base del modulo Delivery.
 */
final class DeliveryService
{
    private const TRANSITIONS = [
        Delivery::STATUS_PENDING => [
            Delivery::STATUS_ASSIGNED,
            Delivery::STATUS_CANCELLED,
        ],
        Delivery::STATUS_ASSIGNED => [
            Delivery::STATUS_PICKED_UP,
            Delivery::STATUS_CANCELLED,
        ],
        Delivery::STATUS_PICKED_UP => [
            Delivery::STATUS_DELIVERED,
        ],
        Delivery::STATUS_DELIVERED => [],
        Delivery::STATUS_CANCELLED => [],
    ];

    public function __construct(
        private DeliveryRepository $repository,
        private OrderRepository $orderRepository,
        private CourierRepository $courierRepository
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
     * @return array<string, mixed>
     */
    public function updateStatus(int $deliveryId, string $status): array
    {
        $delivery = $this->repository->find($deliveryId);

        if ($delivery === null) {
            throw new RecordNotFoundException('Delivery not found.');
        }

        if (! in_array($status, Delivery::allowedStatuses(), true)) {
            throw new InvalidArgumentException(
                'Invalid delivery status.'
            );
        }

        $currentStatus = (string) $delivery['status'];
        $allowedNextStatuses = self::TRANSITIONS[$currentStatus] ?? [];

        if (! in_array($status, $allowedNextStatuses, true)) {
            throw new DomainException(
                'Invalid delivery state transition.'
            );
        }

        $now = current_time('mysql');

        if ($status === Delivery::STATUS_DELIVERED) {
            $this->orderRepository->markDelivered(
                (int) $delivery['order_id'],
                $now
            );
        }

        $this->repository->updateStatus($deliveryId, $status, $now);

        return $this->repository->find($deliveryId)
            ?? throw new RuntimeException(
                'No fue posible recuperar la entrega actualizada.'
            );
    }

    /**
     * @return array<string, mixed>
     */
    public function assignCourier(int $deliveryId, int $courierId): array
    {
        $delivery = $this->repository->find($deliveryId);

        if ($delivery === null) {
            throw new RecordNotFoundException('Delivery not found.');
        }

        if (isset($delivery['courier_id']) && $delivery['courier_id'] !== null) {
            throw new DomainException('Delivery already assigned.');
        }

        if ((string) $delivery['status'] !== Delivery::STATUS_PENDING) {
            throw new DomainException(
                'Delivery cannot be assigned in current state.'
            );
        }

        $courier = $this->courierRepository->find($courierId);

        if ($courier === null) {
            throw new RecordNotFoundException('Courier not found.');
        }

        if (! $this->courierRepository->isApproved($courier)) {
            throw new InvalidArgumentException('Courier is not approved.');
        }

        $this->repository->assignCourier(
            $deliveryId,
            $courierId,
            current_time('mysql')
        );

        return $this->repository->find($deliveryId)
            ?? throw new RuntimeException(
                'No fue posible recuperar la entrega asignada.'
            );
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
