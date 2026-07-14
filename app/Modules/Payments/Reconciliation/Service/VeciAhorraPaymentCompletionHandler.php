<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Reconciliation\Service;

use VeciAhorra\Modules\Payments\Reconciliation\Contracts\PaymentCompletionHandlerInterface;
use VeciAhorra\Modules\Payments\Reconciliation\Contracts\PaymentCompletionOutcomeInterface;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\DurablePaymentOrigin;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\ReconciliationReferences;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\TechnicalReconciliationResult;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\ValidatedFinancialResult;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\VeciAhorraPaymentCompletionOutcome;

final class VeciAhorraPaymentCompletionHandler implements PaymentCompletionHandlerInterface
{
    public function supports(DurablePaymentOrigin $origin): bool
    {
        return $origin->origin() === DurablePaymentOrigin::ORIGIN_VECIAHORRA;
    }

    public function complete(
        ReconciliationReferences $reconciliation,
        DurablePaymentOrigin $origin,
        ValidatedFinancialResult $financialResult,
        TechnicalReconciliationResult $technicalResult
    ): PaymentCompletionOutcomeInterface {
        if (! $this->supports($origin)) {
            throw new \InvalidArgumentException('Origen interno no soportado.');
        }

        return new VeciAhorraPaymentCompletionOutcome();
    }
}
