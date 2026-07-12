<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Models;

final class PaymentSession
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_READY = 'ready';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELLED = 'cancelled';

    public function __construct(
        public readonly int $id,
        public readonly string $publicId,
        public readonly int $checkoutId,
        public readonly string $idempotencyKey,
        public readonly string $requestFingerprint,
        public readonly string $status,
        public readonly ?string $provider,
        public readonly ?string $providerSessionId,
        public readonly ?string $redirectUrl,
        public readonly string $currency,
        public readonly string $amount,
        public readonly ?string $metadata,
        public readonly string $createdAt,
        public readonly string $updatedAt,
        public readonly string $expiresAt
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (int) $data['id'],
            (string) $data['public_id'],
            (int) $data['checkout_id'],
            (string) $data['idempotency_key'],
            (string) $data['request_fingerprint'],
            (string) $data['status'],
            isset($data['provider']) ? (string) $data['provider'] : null,
            isset($data['provider_session_id'])
                ? (string) $data['provider_session_id']
                : null,
            isset($data['redirect_url']) ? (string) $data['redirect_url'] : null,
            (string) $data['currency'],
            (string) $data['amount'],
            isset($data['metadata']) ? (string) $data['metadata'] : null,
            (string) $data['created_at'],
            (string) $data['updated_at'],
            (string) $data['expires_at']
        );
    }

    public static function publicId(): string
    {
        return 'ps_' . rtrim(strtr(
            base64_encode(random_bytes(32)),
            '+/',
            '-_'
        ), '=');
    }

    public static function validPublicId(string $value): bool
    {
        return preg_match('/^ps_[A-Za-z0-9_-]{43}$/D', $value) === 1;
    }
}
