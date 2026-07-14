<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Reconciliation\DTO;

use VeciAhorra\Modules\Payments\Reconciliation\Contracts\PaymentCompletionOutcomeInterface;
use VeciAhorra\Modules\Payments\Reconciliation\Model\PaymentReconciliation;

/** Cierra la etapa financiera interna sin materializar efectos de negocio. */
final class VeciAhorraPaymentCompletionOutcome implements PaymentCompletionOutcomeInterface
{
    public const RESULT = 'veciahorra_business_completion_pending';

    public function successful(): bool { return true; }
    public function resultCode(): string { return self::RESULT; }
    public function targetReconciliationStatus(): string { return PaymentReconciliation::STATUS_COMPLETED; }
    public function lastErrorCode(): ?string { return null; }
}
