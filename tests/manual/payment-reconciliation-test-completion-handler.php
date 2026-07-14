<?php

declare(strict_types=1);

use VeciAhorra\Modules\Payments\Reconciliation\Contracts\PaymentCompletionHandlerInterface;
use VeciAhorra\Modules\Payments\Reconciliation\Contracts\PaymentCompletionOutcomeInterface;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\DurablePaymentOrigin;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\ReconciliationReferences;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\TechnicalReconciliationResult;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\ValidatedFinancialResult;
use VeciAhorra\Modules\Payments\Reconciliation\Model\PaymentReconciliation;

final class TechnicalOnlyCompletionOutcome implements
    PaymentCompletionOutcomeInterface
{
    public function __construct(private readonly string $resultCode)
    {
    }

    public function successful(): bool { return true; }
    public function resultCode(): string { return $this->resultCode; }
    public function targetReconciliationStatus(): string
    {
        return PaymentReconciliation::STATUS_COMPLETED;
    }
    public function lastErrorCode(): ?string { return null; }
}

final class TechnicalOnlyCompletionHandler implements
    PaymentCompletionHandlerInterface
{
    public function supports(DurablePaymentOrigin $origin): bool
    {
        return true;
    }

    public function complete(
        ReconciliationReferences $reconciliation,
        DurablePaymentOrigin $origin,
        ValidatedFinancialResult $financialResult,
        TechnicalReconciliationResult $technicalResult
    ): PaymentCompletionOutcomeInterface {
        return new TechnicalOnlyCompletionOutcome($technicalResult->resultCode());
    }
}
