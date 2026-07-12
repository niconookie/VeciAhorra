<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Checkout\Models;

final class CheckoutOrder
{
    public function __construct(
        public readonly int $id,
        public readonly int $checkoutId,
        public readonly int $orderId,
        public readonly string $createdAt
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (int) $data['id'],
            (int) $data['checkout_id'],
            (int) $data['order_id'],
            (string) $data['created_at']
        );
    }
}
