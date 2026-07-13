<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Reconciliation\DTO;

use InvalidArgumentException;
use VeciAhorra\Modules\Payments\Reconciliation\Model\PaymentReconciliation;
use VeciAhorra\Modules\Payments\Reconciliation\Support\ReconciliationValidation;

final class ReconciliationReferences
{
    public function __construct(
        private readonly int $id,
        private readonly int $webpayReturnId,
        private readonly int $originContextId,
        private readonly string $provider,
        private readonly int $fingerprintVersion,
        private readonly string $financialFingerprint,
        private readonly string $originKey,
        private readonly string $reconciliationStatus
    ) {
        if (
            $id <= 0
            || $webpayReturnId <= 0
            || $originContextId <= 0
            || $provider !== 'webpay_plus'
            || $fingerprintVersion <= 0
            || ! PaymentReconciliation::validStatus($reconciliationStatus)
        ) {
            throw new InvalidArgumentException(
                'Las referencias de conciliacion no son validas.'
            );
        }

        ReconciliationValidation::hash(
            $financialFingerprint,
            'financial_fingerprint'
        );
        ReconciliationValidation::hash($originKey, 'origin_key');
    }

    public function id(): int { return $this->id; }
    public function webpayReturnId(): int { return $this->webpayReturnId; }
    public function originContextId(): int { return $this->originContextId; }
    public function provider(): string { return $this->provider; }
    public function fingerprintVersion(): int { return $this->fingerprintVersion; }
    public function financialFingerprint(): string { return $this->financialFingerprint; }
    public function originKey(): string { return $this->originKey; }
    public function reconciliationStatus(): string { return $this->reconciliationStatus; }
}
