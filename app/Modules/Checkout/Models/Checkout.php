<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Checkout\Models;

final class Checkout
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PAYMENT_STARTED = 'payment_started';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELLED = 'cancelled';

    public function __construct(
        public readonly int $id,
        public readonly string $publicId,
        public readonly string $ownerType,
        public readonly ?int $userId,
        public readonly ?string $sessionId,
        public readonly string $status,
        public readonly string $currency,
        public readonly string $totalAmount,
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
            (string) $data['owner_type'],
            isset($data['user_id']) ? (int) $data['user_id'] : null,
            isset($data['session_id']) ? (string) $data['session_id'] : null,
            (string) $data['status'],
            (string) $data['currency'],
            (string) $data['total_amount'],
            (string) $data['created_at'],
            (string) $data['updated_at'],
            (string) $data['expires_at']
        );
    }

    public static function publicId(): string
    {
        return 'chk_' . rtrim(strtr(
            base64_encode(random_bytes(32)),
            '+/',
            '-_'
        ), '=');
    }

    public static function validPublicId(string $value): bool
    {
        return preg_match('/^chk_[A-Za-z0-9_-]{43}$/D', $value) === 1;
    }
}
