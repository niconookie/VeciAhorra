<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Models;

final class TransactionalPaymentConfirmationResult
{
    public function __construct(
        public readonly string $code,
        public readonly bool $success,
        public readonly bool $idempotent,
        public readonly bool $retryable,
        public readonly string $severity,
        public readonly string $message,
        public readonly string $correlationId,
        public readonly ?int $paymentSessionId = null,
        public readonly ?int $paymentId = null,
        public readonly ?int $checkoutId = null,
        public readonly ?string $fingerprintReference = null
    ) {
    }

    public static function confirmed(
        string $correlationId,
        int $sessionId,
        int $paymentId,
        int $checkoutId,
        string $fingerprint
    ): self {
        return new self(
            'confirmed', true, false, false, 'info',
            'La confirmacion de negocio fue completada.',
            $correlationId, $sessionId, $paymentId, $checkoutId,
            'sha256:' . substr($fingerprint, 0, 12)
        );
    }

    public static function alreadyConfirmed(
        string $correlationId,
        int $sessionId,
        int $paymentId,
        int $checkoutId,
        string $fingerprint
    ): self {
        return new self(
            'already_confirmed', true, true, false, 'info',
            'La confirmacion ya fue aplicada.',
            $correlationId, $sessionId, $paymentId, $checkoutId,
            'sha256:' . substr($fingerprint, 0, 12)
        );
    }

    public static function failure(
        string $code,
        string $correlationId,
        bool $retryable = false,
        string $severity = 'high',
        ?int $sessionId = null,
        ?int $paymentId = null,
        ?int $checkoutId = null
    ): self {
        return new self(
            $code, false, false, $retryable, $severity,
            self::message($code), $correlationId,
            $sessionId, $paymentId, $checkoutId
        );
    }

    private static function message(string $code): string
    {
        return match ($code) {
            'financial_not_approved' => 'El resultado financiero no fue aprobado.',
            'session_not_found' => 'La sesion de pago no es confirmable.',
            'payment_not_found' => 'El pago asociado no es confirmable.',
            'checkout_not_found' => 'El Checkout asociado no es confirmable.',
            'orders_not_found', 'order_set_mismatch' =>
                'Las Orders asociadas no son confirmables.',
            'reservation_expired' =>
                'Las reservas requieren recuperacion o conciliacion.',
            'idempotency_conflict', 'relationship_mismatch',
            'amount_mismatch', 'currency_mismatch', 'buy_order_mismatch',
            'session_identifier_mismatch', 'provider_mismatch' =>
                'La evidencia no coincide con el agregado de pago.',
            'partial_inconsistency' =>
                'El agregado presenta una inconsistencia parcial.',
            'lock_timeout', 'deadlock', 'transient_database_error' =>
                'La confirmacion no pudo completarse temporalmente.',
            'commit_ambiguous' =>
                'El resultado de la confirmacion requiere recuperacion.',
            default => 'La confirmacion no pudo completarse.',
        };
    }
}
