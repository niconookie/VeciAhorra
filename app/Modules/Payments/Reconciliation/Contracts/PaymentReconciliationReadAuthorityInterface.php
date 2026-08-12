<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Reconciliation\Contracts;

use VeciAhorra\Modules\Payments\Reconciliation\Model\PaymentReconciliation;

interface PaymentReconciliationReadAuthorityInterface
{
    public function find(int $id): ?PaymentReconciliation;
}
