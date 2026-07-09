<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Delivery\Models;

/**
 * Representa la entrega base asociada a un pedido pagado.
 */
final class Delivery
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_ASSIGNED = 'assigned';

    public const STATUS_PICKED_UP = 'picked_up';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_CANCELLED = 'cancelled';

    public function __construct(
        public readonly int $id,
        public readonly int $orderId,
        public readonly int $customerId,
        public readonly int $minimarketId,
        public readonly ?int $courierId,
        public readonly string $status,
        public readonly string $createdAt,
        public readonly string $updatedAt
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (int) $data['id'],
            (int) $data['order_id'],
            (int) $data['customer_id'],
            (int) $data['minimarket_id'],
            isset($data['courier_id']) ? (int) $data['courier_id'] : null,
            (string) $data['status'],
            (string) $data['created_at'],
            (string) $data['updated_at']
        );
    }

    /**
     * @return list<string>
     */
    public static function allowedStatuses(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_ASSIGNED,
            self::STATUS_PICKED_UP,
            self::STATUS_DELIVERED,
            self::STATUS_CANCELLED,
        ];
    }
}
