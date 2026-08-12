<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Reconciliation\Contracts;

use VeciAhorra\Modules\Payments\Reconciliation\DTO\PaymentReconciliationProcessingResult;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\ReconciliationLease;

interface PaymentReconciliationAttemptProcessorInterface
{
    public function process(
        ReconciliationLease $lease
    ): PaymentReconciliationProcessingResult;
}
