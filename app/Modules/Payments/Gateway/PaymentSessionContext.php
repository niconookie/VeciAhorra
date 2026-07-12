<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Gateway;

use InvalidArgumentException;

final class PaymentSessionContext
{
    public function __construct(
        public readonly string $paymentSessionId,
        public readonly string $checkoutId,
        public readonly string $amount,
        public readonly string $currency,
        public readonly string $expiresAt,
        public readonly string $idempotencyKey
    ) {
        if (
            $paymentSessionId === ''
            || $checkoutId === ''
            || preg_match('/^\d+(?:\.\d{2})$/D', $amount) !== 1
            || preg_match('/^[A-Z]{3}$/D', $currency) !== 1
            || $expiresAt === ''
            || $idempotencyKey === ''
        ) {
            throw new InvalidArgumentException(
                'El contexto de la sesion de pago no es valido.'
            );
        }
    }

    public function fingerprint(): string
    {
        return hash('sha256', implode('|', [
            $this->paymentSessionId,
            $this->checkoutId,
            $this->amount,
            $this->currency,
            $this->expiresAt,
            $this->idempotencyKey,
        ]));
    }
}
