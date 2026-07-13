<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Reconciliation\DTO;

use InvalidArgumentException;

final class MaterializedReconciliation
{
    public function __construct(
        private readonly int $webpayReturnId,
        private readonly int $reconciliationId,
        private readonly string $transactionReference
    ) {
        if (
            $webpayReturnId <= 0
            || $reconciliationId <= 0
            || preg_match('/^va-wp-v1-[a-f0-9]{64}$/D', $transactionReference) !== 1
        ) {
            throw new InvalidArgumentException('Materializacion no valida.');
        }
    }

    public function webpayReturnId(): int { return $this->webpayReturnId; }
    public function reconciliationId(): int { return $this->reconciliationId; }
    public function transactionReference(): string { return $this->transactionReference; }
}
