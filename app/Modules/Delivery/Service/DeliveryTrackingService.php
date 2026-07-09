<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Delivery\Service;

use DomainException;
use InvalidArgumentException;
use RuntimeException;
use VeciAhorra\Exceptions\RecordNotFoundException;
use VeciAhorra\Modules\Delivery\Models\Delivery;
use VeciAhorra\Modules\Delivery\Models\DeliveryTracking;
use VeciAhorra\Modules\Delivery\Repository\DeliveryRepository;
use VeciAhorra\Modules\Delivery\Repository\DeliveryTrackingRepository;

/**
 * Casos de uso base para seguimiento de entregas.
 */
final class DeliveryTrackingService
{
    public function __construct(
        private DeliveryTrackingRepository $repository,
        private DeliveryRepository $deliveryRepository
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function recordTracking(
        int $deliveryId,
        ?float $latitude,
        ?float $longitude,
        string $event
    ): array {
        $delivery = $this->deliveryRepository->find($deliveryId);

        if ($delivery === null) {
            throw new RecordNotFoundException('Delivery not found.');
        }

        if ((string) $delivery['status'] === Delivery::STATUS_CANCELLED) {
            throw new DomainException('Cannot track cancelled delivery.');
        }

        if (! in_array($event, DeliveryTracking::allowedEvents(), true)) {
            throw new InvalidArgumentException('Invalid tracking event.');
        }

        $this->repository->create([
            'delivery_id' => $deliveryId,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'event' => $event,
            'created_at' => current_time('mysql'),
        ]);

        return $this->repository->latestByDelivery($deliveryId)
            ?? throw new RuntimeException(
                'No fue posible recuperar el evento de seguimiento.'
            );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getTracking(int $deliveryId): array
    {
        $delivery = $this->deliveryRepository->find($deliveryId);

        if ($delivery === null) {
            throw new RecordNotFoundException('Delivery not found.');
        }

        return $this->repository->findByDelivery($deliveryId);
    }
}
