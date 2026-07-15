<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Models;

use InvalidArgumentException;

final class PaymentSession
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_CREATE_PROCESSING = 'create_processing';
    public const STATUS_CREATE_RETRYABLE = 'create_retryable';
    public const STATUS_CREATE_AMBIGUOUS = 'create_ambiguous';
    public const STATUS_CREATE_FAILED = 'create_failed';
    public const STATUS_READY = 'ready';
    public const STATUS_CONFIRMED = 'confirmed';
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
        public readonly string $expiresAt,
        public readonly ?int $paymentId = null,
        public readonly ?string $confirmationFingerprint = null,
        public readonly ?int $confirmationFingerprintVersion = null,
        public readonly ?string $safeFinancialReference = null,
        public readonly ?string $confirmedAt = null
    ) {
        self::assertState($status, $confirmedAt);
        self::assertConfirmationEvidence(
            $confirmationFingerprint,
            $confirmationFingerprintVersion,
            $safeFinancialReference
        );

        if ($paymentId !== null && $paymentId <= 0) {
            throw new InvalidArgumentException('payment_id no es valido.');
        }
    }

    public static function fromArray(array $data): self
    {
        foreach ([
            'payment_id',
            'confirmation_fingerprint_version',
        ] as $integerField) {
            $value = $data[$integerField] ?? null;

            if (
                $value !== null
                && (is_bool($value) || is_float($value) || is_array($value))
            ) {
                throw new InvalidArgumentException(
                    "{$integerField} no es valido."
                );
            }
        }

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
            (string) $data['expires_at'],
            isset($data['payment_id']) ? (int) $data['payment_id'] : null,
            isset($data['confirmation_fingerprint'])
                ? (string) $data['confirmation_fingerprint']
                : null,
            isset($data['confirmation_fingerprint_version'])
                ? (int) $data['confirmation_fingerprint_version']
                : null,
            isset($data['safe_financial_reference'])
                ? (string) $data['safe_financial_reference']
                : null,
            isset($data['confirmed_at'])
                ? (string) $data['confirmed_at']
                : null
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

    public static function validStatus(string $status): bool
    {
        return in_array($status, [
            self::STATUS_PENDING,
            self::STATUS_CREATE_PROCESSING,
            self::STATUS_CREATE_RETRYABLE,
            self::STATUS_CREATE_AMBIGUOUS,
            self::STATUS_CREATE_FAILED,
            self::STATUS_READY,
            self::STATUS_CONFIRMED,
            self::STATUS_EXPIRED,
            self::STATUS_CANCELLED,
        ], true);
    }

    private static function assertState(
        string $status,
        ?string $confirmedAt
    ): void {
        if (! self::validStatus($status)) {
            throw new InvalidArgumentException(
                'El estado de PaymentSession no es valido.'
            );
        }

        if (
            ($status === self::STATUS_CONFIRMED) !== ($confirmedAt !== null)
            || ($confirmedAt !== null
                && preg_match(
                    '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D',
                    $confirmedAt
                ) !== 1)
        ) {
            throw new InvalidArgumentException(
                'confirmed_at no es coherente con el estado de la sesion.'
            );
        }
    }

    private static function assertConfirmationEvidence(
        ?string $fingerprint,
        ?int $version,
        ?string $safeReference
    ): void {
        if (
            ($fingerprint === null) !== ($version === null)
            || ($fingerprint !== null
                && preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1)
            || ($version !== null && $version <= 0)
            || ($safeReference !== null
                && preg_match(
                    '/^sha256:[a-f0-9]{12,56}$/D',
                    $safeReference
                ) !== 1)
        ) {
            throw new InvalidArgumentException(
                'La evidencia de confirmacion no es valida.'
            );
        }
    }
}
