<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Models;

final class Payment
{
    public function __construct(
        public readonly int $id,
        public readonly string $paymentReference,
        public readonly int $customerId,
        public readonly string $amount,
        public readonly string $currency,
        public readonly string $status,
        public readonly ?string $provider,
        public readonly ?string $providerReference,
        public readonly ?string $expiresAt
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (int) $data['id'],
            (string) $data['payment_reference'],
            (int) $data['customer_id'],
            (string) $data['amount'],
            (string) $data['currency'],
            (string) $data['status'],
            isset($data['provider']) ? (string) $data['provider'] : null,
            isset($data['provider_reference'])
                ? (string) $data['provider_reference']
                : null,
            isset($data['expires_at']) ? (string) $data['expires_at'] : null
        );
    }
}
