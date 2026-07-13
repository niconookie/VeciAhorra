<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\WooCommerce\DTO;

use InvalidArgumentException;

final class WooCommercePaymentAttempt
{
    public function __construct(
        private readonly int $originContextId,
        private readonly string $paymentAttemptId
    ) {
        if (
            $originContextId <= 0
            || preg_match('/^attempt_[a-f0-9]{32}$/D', $paymentAttemptId) !== 1
        ) {
            throw new InvalidArgumentException('Intento WooCommerce no valido.');
        }
    }

    public function originContextId(): int { return $this->originContextId; }
    public function paymentAttemptId(): string { return $this->paymentAttemptId; }
}
