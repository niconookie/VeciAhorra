<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Delivery\Models;

/**
 * Representa un evento de seguimiento de entrega.
 */
final class DeliveryTracking
{
    public const EVENT_ASSIGNED = 'assigned';

    public const EVENT_PICKED_UP = 'picked_up';

    public const EVENT_LOCATION_UPDATE = 'location_update';

    public const EVENT_DELIVERED = 'delivered';

    public function __construct(
        public readonly int $id,
        public readonly int $deliveryId,
        public readonly ?string $latitude,
        public readonly ?string $longitude,
        public readonly string $event,
        public readonly string $createdAt
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (int) $data['id'],
            (int) $data['delivery_id'],
            isset($data['latitude']) ? (string) $data['latitude'] : null,
            isset($data['longitude']) ? (string) $data['longitude'] : null,
            (string) $data['event'],
            (string) $data['created_at']
        );
    }

    /**
     * @return list<string>
     */
    public static function allowedEvents(): array
    {
        return [
            self::EVENT_ASSIGNED,
            self::EVENT_PICKED_UP,
            self::EVENT_LOCATION_UPDATE,
            self::EVENT_DELIVERED,
        ];
    }
}
