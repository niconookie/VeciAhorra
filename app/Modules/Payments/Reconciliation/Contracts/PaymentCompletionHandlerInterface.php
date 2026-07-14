<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Reconciliation\Contracts;

use VeciAhorra\Modules\Payments\Reconciliation\DTO\DurablePaymentOrigin;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\ReconciliationReferences;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\TechnicalReconciliationResult;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\ValidatedFinancialResult;

interface PaymentCompletionHandlerInterface
{
    public function supports(DurablePaymentOrigin $origin): bool;

    public function complete(
        ReconciliationReferences $reconciliation,
        DurablePaymentOrigin $origin,
        ValidatedFinancialResult $financialResult,
        TechnicalReconciliationResult $technicalResult
    ): PaymentCompletionOutcomeInterface;
}
