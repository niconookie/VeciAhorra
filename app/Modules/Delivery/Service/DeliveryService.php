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
    public function __construct(
        private DeliveryRepository $repository,
        private OrderRepository $orderRepository,
        private CourierRepository $courierRepository,
        private DeliveryTrackingService $trackingService
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

        if ($this->repository->exists($orderId)) {
            throw new DomainException(
                'Delivery already exists for order.'
            );
        }

        $checkout = (new \VeciAhorra\Modules\Delivery\Completion\Repository\DeliveryCompletionRepository())->checkoutForOrder($orderId);
        $zoneId = (int) ($order['service_zone_id'] ?? 0);
        if ($zoneId <= 0 || $zoneId !== (int) ($checkout['service_zone_id'] ?? 0)
            || ($checkout['fulfillment_method'] ?? null) !== 'delivery') {
            throw new InvalidArgumentException('delivery_zone_snapshot_invalid');
        }
        (new \VeciAhorra\Modules\Sectorization\TerritorialAuthority())->storeInZone($zoneId, (int) $order['minimarket_id']);
        $snapshot = [];
        foreach (['delivery_recipient_name','delivery_contact_phone','delivery_address_line1','delivery_commune','delivery_reference','delivery_notes'] as $field) {
            $snapshot[$field] = $checkout[$field] ?? null;
        }
        $now = current_time('mysql');
        $deliveryId = $this->repository->create([
            ...$snapshot,
            'service_zone_id' => $zoneId,
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
    public function updateStatus(int $deliveryId, string $status, int $version = -1): array
    {
        return (new \VeciAhorra\Modules\Couriers\Service\CourierDeliveryService())->adminTransition($deliveryId,$status,$version);
    }

    /**
     * @return array<string, mixed>
     */
    public function assignCourier(int $deliveryId, int $courierId, ?int $version = null, ?string $reason = null): array
    {
        $service = new \VeciAhorra\Modules\Couriers\Service\CourierDeliveryService();
        if ($reason !== null) return $service->adminChange($deliveryId,$courierId > 0 ? $courierId : null,$reason,$version ?? -1);
        return $service->adminAssign($deliveryId,$courierId,$version);
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
