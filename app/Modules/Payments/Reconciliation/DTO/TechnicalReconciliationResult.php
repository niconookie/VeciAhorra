<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Reconciliation\DTO;

use InvalidArgumentException;
use VeciAhorra\Modules\Payments\Reconciliation\Support\ReconciliationValidation;

final class TechnicalReconciliationResult
{
    public function __construct(
        private readonly int $reconciliationId,
        private readonly string $resultCode,
        private readonly string $financialFingerprint,
        private readonly string $originKey
    ) {
        if (
            $reconciliationId <= 0
            || preg_match('/^technical_[a-z_]{3,40}$/D', $resultCode) !== 1
        ) {
            throw new InvalidArgumentException('El resultado tecnico no es valido.');
        }

        ReconciliationValidation::hash(
            $financialFingerprint,
            'financial_fingerprint'
        );
        ReconciliationValidation::hash($originKey, 'origin_key');
    }

    public function reconciliationId(): int { return $this->reconciliationId; }
    public function resultCode(): string { return $this->resultCode; }
    public function financialFingerprint(): string { return $this->financialFingerprint; }
    public function originKey(): string { return $this->originKey; }
}
