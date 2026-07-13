<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Reconciliation\Support;

use VeciAhorra\Modules\Payments\Reconciliation\Contracts\ReconciliationClockInterface;

final class SystemReconciliationClock implements ReconciliationClockInterface
{
    public function now(): int
    {
        return time();
    }
}
