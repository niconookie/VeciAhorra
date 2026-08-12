<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Reconciliation\Contracts;

use VeciAhorra\Modules\Payments\Reconciliation\DTO\LeaseAcquireResult;

interface PaymentReconciliationLeaseAuthorityInterface
{
    public function newOwner(): string;

    public function acquireLease(
        int $reconciliationId,
        string $owner
    ): LeaseAcquireResult;
}
