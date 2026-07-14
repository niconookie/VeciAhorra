<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Reconciliation\Contracts;

interface PaymentCompletionOutcomeInterface
{
    public function successful(): bool;

    public function resultCode(): string;

    public function targetReconciliationStatus(): string;

    public function lastErrorCode(): ?string;
}
